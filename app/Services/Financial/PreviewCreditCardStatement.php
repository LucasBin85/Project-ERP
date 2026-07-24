<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PreviewCreditCardStatement
{
    public function __construct(
        private readonly ParseCreditCardStatementFile $parser,
        private readonly NormalizeBankStatementDescription $descriptions,
    ) {}

    public function execute(Wallet $wallet, CreditCard $card, string $contents, string $filename): array
    {
        if ((int) $card->wallet_id !== (int) $wallet->id || ! $card->is_active) {
            throw ValidationException::withMessages(['statement_file' => 'O cartão deve estar ativo e pertencer à wallet atual.']);
        }
        $mainCard = $card->parentCard ?: $card;
        $parsed = $this->parser->parse($contents, $filename);
        $fileHash = hash('sha256', $this->parser->format($filename).'|'.$contents);
        $seen = [];
        $rows = [];

        foreach ($parsed['transactions'] as $index => $transaction) {
            [$installmentNumber, $installmentsTotal] = $this->installment($transaction->description);
            $normalized = $this->descriptions->execute($transaction->description);
            $hash = hash('sha256', implode('|', [
                $mainCard->id, $transaction->postedAt, $normalized, $transaction->amountCents,
            ]));
            $duplicateInFile = isset($seen[$hash]);
            $seen[$hash] = true;
            $existing = CreditCardTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where(function ($query) use ($mainCard, $card) {
                    $query->where('credit_card_id', $card->id)
                        ->orWhereHas('creditCard', fn ($query) => $query->where('parent_card_id', $mainCard->id));
                })
                ->where(fn ($query) => $query->where('import_hash', $hash)->orWhere('external_id', $transaction->fitId))
                ->exists();
            $credit = $transaction->direction === 'in';
            $situation = $duplicateInFile ? 'possible_duplicate' : ($existing ? 'already_imported' : ($credit ? 'credit' : 'new'));

            $rows[] = [
                'row_key' => hash('sha256', $fileHash.'|'.$index.'|'.$hash),
                'index' => $index,
                'date' => $transaction->postedAt,
                'description' => $transaction->description,
                'amount_cents' => $transaction->amountCents,
                'external_id' => $transaction->fitId,
                'import_hash' => $hash,
                'installment_number' => $installmentNumber,
                'installments_total' => $installmentsTotal,
                'invoice_reference' => $this->invoiceReference($mainCard, $transaction->postedAt),
                'situation' => $situation,
                'default_action' => $situation === 'new' ? 'create' : 'ignore',
            ];
        }

        return [
            'token' => null,
            'file_name' => $filename,
            'format' => strtoupper($this->parser->format($filename)),
            'origin' => $this->parser->format($filename) === 'pdf' && $parsed['read_source'] === 'ocr' ? 'PDF/OCR' : strtoupper($this->parser->format($filename)),
            'file_hash' => $fileHash,
            'credit_card_id' => $card->id,
            'credit_card_name' => $card->name,
            'institution' => $parsed['institution'] ?? null,
            'last_four' => $parsed['last_four'] ?? null,
            'holder_name' => $parsed['holder_name'] ?? null,
            'due_date' => $parsed['due_date'] ?? null,
            'rows' => $rows,
            'summary' => [
                'total_cents' => (int) collect($rows)->where('situation', '!=', 'credit')->sum('amount_cents'),
                'new' => collect($rows)->where('situation', 'new')->count(),
                'already_imported' => collect($rows)->where('situation', 'already_imported')->count(),
                'possible_duplicate' => collect($rows)->where('situation', 'possible_duplicate')->count(),
                'credits' => collect($rows)->where('situation', 'credit')->count(),
            ],
        ];
    }

    private function installment(string $description): array
    {
        if (preg_match('/\b(\d{1,2})\s*\/\s*(\d{1,2})\b/u', $description, $match)) {
            return [(int) $match[1], (int) $match[2]];
        }

        return [1, 1];
    }

    private function invoiceReference(CreditCard $card, string $date): string
    {
        $purchase = CarbonImmutable::parse($date);
        $reference = $purchase->day <= $card->closing_day ? $purchase : $purchase->addMonthNoOverflow();

        return $reference->format('m/Y');
    }
}
