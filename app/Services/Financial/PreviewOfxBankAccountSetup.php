<?php

namespace App\Services\Financial;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Wallet;
use Illuminate\Support\Str;

class PreviewOfxBankAccountSetup
{
    public function __construct(
        private readonly ParseOfxStatement $ofx,
        private readonly ParseCsvStatement $csv,
        private readonly ParsePdfStatement $pdf,
        private readonly ParseStatementFile $files,
    ) {}

    /**
     * @return array{
     *     file_name: string,
     *     account: array{
     *         container: ?string,
     *         bank_code: ?string,
     *         ispb: ?string,
     *         agency: ?string,
     *         account_number: ?string,
     *         account_digit: ?string,
     *         account_type: ?string,
     *         raw_account_number: ?string,
     *         raw_account_type: ?string
     *     },
     *     matched_bank: ?array{id: int, code: string, name: string, short_name: string, ispb: string},
     *     suggested: array{
     *         bank_id: ?int,
     *         name: ?string,
     *         agency: ?string,
     *         account_number: ?string,
     *         account_type: ?string
     *     },
     *     warnings: array<int, string>,
     *     message: string
     * }
     */
    public function execute(Wallet $wallet, string $contents, string $originalFilename): array
    {
        $format = $this->files->format($originalFilename);
        $source = strtoupper($format);
        $metadata = match ($format) {
            'ofx' => $this->ofx->parseAccountMetadata($contents),
            'csv' => $this->csvMetadata($contents),
            'pdf' => $this->pdfMetadata($contents, $source),
        };
        $warnings = [];

        $bankCode = $this->normalizeBankCode($metadata['bank_id']);
        $ispb = $this->normalizeIspb($metadata['routing_number'])
            ?? $this->normalizeIspb($metadata['bank_id']);

        [$matchedBank, $bankWarning] = $this->resolveBank($bankCode, $ispb);

        if ($bankWarning !== null) {
            $warnings[] = $bankWarning;
        }

        $agency = $this->digits($metadata['branch_id']);
        [$accountNumber, $accountDigit, $suggestedAccountNumber] = $this->splitAccountNumber(
            $metadata['account_id'],
            $metadata['account_key'],
        );
        $accountType = $this->normalizeAccountType($metadata['account_type']);

        if ($format === 'ofx' && $metadata['container'] !== 'BANKACCTFROM') {
            $warnings[] = 'O extrato não identifica uma conta bancária transacional. Revise os dados antes de salvar.';
        }

        if ($bankCode === null && $ispb === null) {
            $warnings[] = 'O arquivo do extrato não informa um código de banco ou ISPB. Selecione o banco manualmente.';
        } elseif ($matchedBank === null && $bankWarning === null) {
            $warnings[] = 'O banco informado no extrato não foi encontrado no catálogo local. Selecione o banco manualmente.';
        }

        if ($agency === null) {
            $warnings[] = 'O arquivo do extrato não informa a agência. Preencha esse campo manualmente.';
        }

        if ($suggestedAccountNumber === null) {
            $warnings[] = 'O arquivo do extrato não informa um número de conta válido. Preencha esse campo manualmente.';
        }

        if ($accountType === null) {
            $warnings[] = 'O tipo da conta não foi informado ou não pôde ser reconhecido. Revise esse campo manualmente.';
        }

        if ($accountDigit !== null && ! ctype_digit($accountDigit)) {
            $warnings[] = 'O dígito da conta é alfanumérico e não foi incorporado ao campo numérico. Revise o número da conta.';
        }

        $suggestedName = $this->suggestName(
            wallet: $wallet,
            bank: $matchedBank,
            institutionName: $metadata['bank_name'] ?? $metadata['organization'],
            agency: $agency,
            accountNumber: $accountNumber,
            accountDigit: $accountDigit,
        );
        $warnings = array_values(array_unique($warnings));

        $noIdentifiedMetadata = $matchedBank === null && $agency === null && $suggestedAccountNumber === null;
        if ($format === 'csv' && $noIdentifiedMetadata) {
            array_unshift($warnings, 'Não foi possível identificar dados bancários neste CSV.');
        } elseif ($format === 'pdf' && $noIdentifiedMetadata) {
            array_unshift($warnings, 'Não foi possível identificar dados bancários neste PDF.');
        }

        return [
            'file_name' => $originalFilename,
            'format' => strtoupper($format),
            'source' => $source,
            'account' => [
                'container' => $metadata['container'],
                'bank_code' => $bankCode,
                'ispb' => $ispb,
                'agency' => $agency,
                'account_number' => $accountNumber,
                'account_digit' => $accountDigit,
                'account_type' => $accountType,
                'raw_account_number' => $metadata['account_id'],
                'raw_account_type' => $metadata['account_type'],
            ],
            'matched_bank' => $matchedBank ? [
                'id' => $matchedBank->id,
                'code' => $matchedBank->code,
                'name' => $matchedBank->name,
                'short_name' => $matchedBank->short_name,
                'ispb' => $matchedBank->ispb,
            ] : null,
            'suggested' => [
                'bank_id' => $matchedBank?->id,
                'name' => $suggestedName,
                'agency' => $agency,
                'account_number' => $suggestedAccountNumber,
                'account_type' => $accountType,
            ],
            'warnings' => $warnings,
            'message' => $noIdentifiedMetadata && in_array($format, ['csv', 'pdf'], true)
                ? 'Continue preenchendo os dados da conta manualmente.'
                : ($warnings === []
                ? 'Dados bancários encontrados no arquivo do extrato. Revise as informações antes de salvar.'
                : 'Os dados disponíveis no extrato foram preenchidos. Complete ou revise os campos indicados.'),
        ];
    }

    /** @return array<string, ?string> */
    private function csvMetadata(string $contents): array
    {
        $this->csv->parse($contents);
        return $this->emptyMetadata();
    }

    /** @return array<string, ?string> */
    private function pdfMetadata(string $contents, string &$source): array
    {
        $extraction = $this->pdf->extractForMetadata($contents);
        $text = $extraction['text'];
        $source = $extraction['source'] === 'ocr' ? 'PDF/OCR' : 'PDF';
        $metadata = $this->emptyMetadata();

        if (preg_match('/\bMercado\s+Pago\b/ui', $text)) {
            $bank = Bank::query()->where('active', true)
                ->where(fn ($query) => $query->where('name', 'like', '%Mercado Pago%')->orWhere('short_name', 'like', '%Mercado Pago%'))
                ->first(['code', 'ispb', 'name']);
            $metadata['bank_id'] = $bank?->code;
            $metadata['routing_number'] = $bank?->ispb;
            $metadata['bank_name'] = $bank?->name ?? 'Mercado Pago';
            $metadata['organization'] = 'Mercado Pago';
        }

        if (preg_match('/\bAg[eêé]ncia\s*:?\s*([\d.\/-]+)/ui', $text, $agency)) {
            $metadata['branch_id'] = $agency[1];
        }
        if (preg_match('/\bConta\s*:?\s*([\d.\/-]+)/ui', $text, $account)) {
            $metadata['account_id'] = $account[1];
        }
        if ($metadata['account_id'] !== null || $metadata['branch_id'] !== null) {
            $metadata['container'] = 'BANKACCTFROM';
            $metadata['account_type'] = 'CHECKING';
        }

        return $metadata;
    }

    /** @return array<string, ?string> */
    private function emptyMetadata(): array
    {
        return array_fill_keys(['container', 'bank_id', 'branch_id', 'account_id', 'account_key', 'account_type', 'broker_id', 'routing_number', 'bank_name', 'organization', 'financial_institution_id', 'currency'], null);
    }

    /** @return array{0: ?Bank, 1: ?string} */
    private function resolveBank(?string $bankCode, ?string $ispb): array
    {
        $banks = Bank::query()
            ->where('active', true)
            ->get(['id', 'code', 'name', 'short_name', 'ispb']);

        $bankByCode = $bankCode === null
            ? null
            : $banks->first(fn (Bank $bank) => $this->normalizeBankCode($bank->code) === $bankCode);
        $bankByIspb = $ispb === null
            ? null
            : $banks->first(fn (Bank $bank) => $this->normalizeIspb($bank->ispb) === $ispb);

        if ($bankByCode && $bankByIspb && ! $bankByCode->is($bankByIspb)) {
            return [
                null,
                'O código do banco e o ISPB do extrato identificam instituições diferentes. Selecione o banco manualmente.',
            ];
        }

        return [$bankByIspb ?? $bankByCode, null];
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function splitAccountNumber(?string $rawAccountNumber, ?string $rawAccountKey): array
    {
        $rawAccountNumber = $this->nullableString($rawAccountNumber);

        if ($rawAccountNumber === null) {
            return [null, $this->normalizeAccountDigit($rawAccountKey), null];
        }

        $base = $rawAccountNumber;
        $delimitedDigit = null;

        if (preg_match('/^(.+?)[\-\/.]([A-Z0-9])$/i', $rawAccountNumber, $match)) {
            $base = $match[1];
            $delimitedDigit = strtoupper($match[2]);
        }

        $accountNumber = $this->digits($base);
        $accountDigit = $this->normalizeAccountDigit($rawAccountKey) ?? $delimitedDigit;

        if ($accountNumber === null) {
            return [null, $accountDigit, null];
        }

        $suggestedAccountNumber = $accountNumber;

        if ($accountDigit !== null && ctype_digit($accountDigit)) {
            $suggestedAccountNumber .= $accountDigit;
        }

        return [$accountNumber, $accountDigit, $suggestedAccountNumber];
    }

    private function normalizeBankCode(?string $value): ?string
    {
        $digits = $this->digits($value);

        if ($digits === null || strlen($digits) > 4) {
            return null;
        }

        $significant = ltrim($digits, '0');

        if ($significant === '' || strlen($significant) > 3) {
            return null;
        }

        return str_pad($significant, 3, '0', STR_PAD_LEFT);
    }

    private function normalizeIspb(?string $value): ?string
    {
        $digits = $this->digits($value);

        return $digits !== null && strlen($digits) === 8 ? $digits : null;
    }

    private function normalizeAccountDigit(?string $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));

        return strlen($normalized) === 1 ? $normalized : null;
    }

    private function normalizeAccountType(?string $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));

        return match ($normalized) {
            'CHECKING', 'CHECK', 'CURRENT', 'CORRENTE' => 'checking',
            'SAVINGS', 'SAVING', 'POUPANCA' => 'savings',
            'INVESTMENT', 'INVEST', 'MONEYMRKT', 'MONEYMARKET', 'CD' => 'investment',
            'CASH' => 'cash',
            'OTHER' => 'other',
            default => null,
        };
    }

    private function digits(?string $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : $digits;
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function suggestName(
        Wallet $wallet,
        ?Bank $bank,
        ?string $institutionName,
        ?string $agency,
        ?string $accountNumber,
        ?string $accountDigit,
    ): string {
        $institution = $bank?->short_name
            ?? $this->nullableString($institutionName)
            ?? 'Conta bancária';
        $identifier = $accountNumber;

        if ($identifier !== null && $accountDigit !== null) {
            $identifier .= '-'.$accountDigit;
        }

        $baseName = $identifier !== null
            ? sprintf('%s - Conta %s', $institution, $identifier)
            : ($agency !== null ? sprintf('%s - Agência %s', $institution, $agency) : $institution);
        $baseName = Str::limit(Str::squish($baseName), 255, '');

        if (! BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('name', $baseName)
            ->exists()) {
            return $baseName;
        }

        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $candidate = Str::limit($baseName, 250, '').' '.$suffix;

            if (! BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('name', $candidate)
                ->exists()) {
                return $candidate;
            }
        }

        return Str::limit($baseName, 240, '').' '.Str::random(8);
    }
}
