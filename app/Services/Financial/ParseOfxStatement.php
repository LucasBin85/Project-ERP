<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ParseOfxStatement
{
    /**
     * @return array{
     *     started_at: ?string,
     *     ended_at: ?string,
     *     account: array{
     *         container: ?string,
     *         bank_id: ?string,
     *         branch_id: ?string,
     *         account_id: ?string,
     *         account_type: ?string,
     *         broker_id: ?string,
     *         routing_number: ?string,
     *         bank_name: ?string,
     *         organization: ?string,
     *         financial_institution_id: ?string,
     *         currency: ?string
     *     },
     *     transactions: array<int, ParsedOfxTransactionDTO>,
     *     errors: array<int, array{index: int, message: string}>
     * }
     */
    public function parse(string $contents): array
    {
        $contents = $this->normalize($contents);
        $this->ensureSingleAccount($contents);

        preg_match('/<DTSTART>([^<\r\n]+)/i', $contents, $startMatch);
        preg_match('/<DTEND>([^<\r\n]+)/i', $contents, $endMatch);
        preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>)/is', $contents, $matches);

        $blocks = $matches[1] ?? [];

        if ($blocks === []) {
            throw new RuntimeException('Nenhuma transação encontrada no arquivo OFX.');
        }

        $transactions = [];
        $errors = [];

        foreach ($blocks as $index => $block) {
            try {
                $transactions[$index] = $this->parseTransaction($block);
            } catch (Throwable $exception) {
                $errors[] = [
                    'index' => $index,
                    'message' => sprintf('Linha %d: %s', $index + 1, $exception->getMessage()),
                ];
            }
        }

        return [
            'started_at' => isset($startMatch[1]) ? $this->parseDate($startMatch[1]) : null,
            'ended_at' => isset($endMatch[1]) ? $this->parseDate($endMatch[1]) : null,
            'account' => $this->parseAccount($contents),
            'transactions' => $transactions,
            'errors' => $errors,
        ];
    }

    private function ensureSingleAccount(string $contents): void
    {
        preg_match_all('/<(?:BANKACCTFROM|CCACCTFROM|INVACCTFROM)>/i', $contents, $matches);

        if (count($matches[0] ?? []) > 1) {
            throw new RuntimeException(
                'O arquivo OFX contém mais de uma conta. Exporte e importe um arquivo separado para cada conta bancária.',
            );
        }
    }

    /**
     * @return array{
     *     container: ?string,
     *     bank_id: ?string,
     *     branch_id: ?string,
     *     account_id: ?string,
     *     account_type: ?string,
     *     broker_id: ?string,
     *     routing_number: ?string,
     *     bank_name: ?string,
     *     organization: ?string,
     *     financial_institution_id: ?string,
     *     currency: ?string
     * }
     */
    private function parseAccount(string $contents): array
    {
        $container = null;
        $accountScope = '';

        foreach (['BANKACCTFROM', 'CCACCTFROM', 'INVACCTFROM'] as $candidate) {
            $pattern = '/<'.preg_quote($candidate, '/').'>(.*?)(?=<\/'.preg_quote($candidate, '/').'>|<BANKTRANLIST>|<INVTRANLIST>|<STMTTRN>|<\/STMTRS>|<\/CCSTMTRS>|<\/INVSTMTRS>)/is';

            if (preg_match($pattern, $contents, $match)) {
                $container = $candidate;
                $accountScope = $match[1];

                break;
            }
        }

        return [
            'container' => $container,
            'bank_id' => $this->tag($accountScope, 'BANKID'),
            'branch_id' => $this->tag($accountScope, 'BRANCHID'),
            'account_id' => $this->tag($accountScope, 'ACCTID'),
            'account_type' => $this->tag($accountScope, 'ACCTTYPE'),
            'broker_id' => $this->tag($accountScope, 'BROKERID'),
            'routing_number' => $this->tag($accountScope, 'ROUTINGNUM'),
            'bank_name' => $this->tag($accountScope, 'BANKNAME'),
            'organization' => $this->tag($contents, 'ORG'),
            'financial_institution_id' => $this->tag($contents, 'FID'),
            'currency' => $this->tag($contents, 'CURDEF'),
        ];
    }

    private function parseTransaction(string $block): ParsedOfxTransactionDTO
    {
        $amount = $this->tag($block, 'TRNAMT');
        $postedAt = $this->tag($block, 'DTPOSTED') ?: $this->tag($block, 'DTUSER');

        if ($amount === null || $postedAt === null) {
            throw new RuntimeException('a transação não possui data e valor válidos.');
        }

        $providedFitId = $this->tag($block, 'FITID');
        $fitId = $providedFitId
            ?: sha1($postedAt.'|'.$amount.'|'.$this->tag($block, 'MEMO').'|'.$this->tag($block, 'NAME'));

        $amountCents = $this->moneyToCents($amount);

        if ($amountCents === 0) {
            throw new RuntimeException('o valor da transação deve ser diferente de zero.');
        }

        $direction = $amountCents >= 0 ? 'in' : 'out';
        $absoluteAmountCents = abs($amountCents);

        $name = $this->tag($block, 'NAME');
        $memo = $this->tag($block, 'MEMO');
        $payee = $this->tag($block, 'PAYEE');
        $checkNumber = $this->tag($block, 'CHECKNUM');
        $transactionType = $this->tag($block, 'TRNTYPE');

        $description = collect([$name, $memo, $payee])
            ->filter()
            ->unique()
            ->join(' - ');

        if ($description === '') {
            $description = $transactionType ?: 'Transação OFX';
        }

        return new ParsedOfxTransactionDTO(
            fitId: trim($fitId),
            postedAt: $this->parseDate($postedAt),
            amountCents: $absoluteAmountCents,
            direction: $direction,
            description: Str::limit($this->cleanText($description), 255, ''),
            raw: [
                'trntype' => $transactionType,
                'dtposted' => $postedAt,
                'trnamt' => $amount,
                'fitid' => $providedFitId,
                'fitid_provided' => $providedFitId !== null,
                'name' => $name,
                'memo' => $memo,
                'payee' => $payee,
                'checknum' => $checkNumber,
            ],
        );
    }

    private function tag(string $block, string $tag): ?string
    {
        if (! preg_match('/<'.preg_quote($tag, '/').'>[^<\r\n]*/i', $block, $match)) {
            return null;
        }

        $value = preg_replace('/^<'.preg_quote($tag, '/').'>/i', '', $match[0]);
        $value = $this->cleanText($value);

        return $value === '' ? null : $value;
    }

    private function moneyToCents(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));

        if (! is_numeric($normalized)) {
            throw new RuntimeException('o valor da transação é inválido.');
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function parseDate(string $value): string
    {
        $date = substr(trim($value), 0, 8);

        if (! preg_match('/^\d{8}$/', $date)) {
            throw new RuntimeException('a data da transação é inválida.');
        }

        return CarbonImmutable::createFromFormat('!Ymd', $date)->toDateString();
    }

    private function normalize(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        return mb_convert_encoding($contents, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    private function cleanText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
