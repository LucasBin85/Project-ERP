<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmCreditCardStatement
{
    public function __construct(
        private readonly ParseCreditCardStatementFile $parser,
        private readonly ResolveCreditCardInvoice $invoices,
        private readonly CreateJournalEntry $journalEntries,
    ) {}

    public function execute(
        Wallet $wallet,
        CreditCard $card,
        array $preview,
        string $contents,
        string $filename,
        array $decisions,
        ?int $targetYear = null,
        ?int $targetMonth = null,
    ): array {
        if ((int) $card->wallet_id !== (int) $wallet->id || ! $wallet->suspense_account_id) {
            throw ValidationException::withMessages(['statement_import' => 'Cartão ou conta A classificar inválidos para esta importação.']);
        }

        $targetYear ??= isset($preview['target_invoice']['reference_year']) ? (int) $preview['target_invoice']['reference_year'] : null;
        $targetMonth ??= isset($preview['target_invoice']['reference_month']) ? (int) $preview['target_invoice']['reference_month'] : null;
        if (! $targetYear || ! $targetMonth) {
            throw ValidationException::withMessages([
                'target_invoice' => 'Selecione o mês e o ano da fatura alvo antes de confirmar.',
            ]);
        }

        return DB::transaction(function () use ($wallet, $card, $preview, $contents, $filename, $decisions, $targetYear, $targetMonth) {
            $mainCard = $this->invoices->mainCard($card);
            $familyCardIds = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where(fn ($query) => $query->whereKey($mainCard->id)->orWhere('parent_card_id', $mainCard->id))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $parsed = $this->parser->parse($contents, $filename);
            $detectedTarget = $preview['target_invoice'] ?? null;
            $nominalDueDate = (int) ($detectedTarget['reference_year'] ?? 0) === $targetYear
                && (int) ($detectedTarget['reference_month'] ?? 0) === $targetMonth
                ? ($detectedTarget['nominal_due_at'] ?? null)
                : null;
            $invoice = $this->invoices->forReference($wallet, $mainCard, $targetYear, $targetMonth, $nominalDueDate);
            $decisionMap = collect($decisions)->keyBy('row_key');
            $created = 0;
            $ignored = 0;

            foreach ($preview['rows'] as $row) {
                $decision = $decisionMap->get($row['row_key']);
                if (($decision['action'] ?? 'ignore') !== 'create' || $row['situation'] !== 'new') {
                    $ignored++;

                    continue;
                }
                $targetCardId = in_array((int) ($row['credit_card_id'] ?? 0), $familyCardIds, true)
                    ? (int) $row['credit_card_id']
                    : $mainCard->id;
                if (CreditCardTransaction::query()->whereIn('credit_card_id', $familyCardIds)
                    ->where(fn ($query) => $query->where('import_hash', $row['import_hash'])->orWhere('external_id', $row['external_id']))->exists()) {
                    $ignored++;

                    continue;
                }

                $transaction = $parsed['transactions'][$row['index']];
                $entry = $this->journalEntries->execute([
                    'wallet_id' => $wallet->id,
                    'entry_date' => $transaction->postedAt,
                    'description' => 'Compra importada no cartão: '.$transaction->description,
                    'lines' => [
                        ['chart_of_account_id' => $wallet->suspense_account_id, 'type' => 'debit', 'amount_cents' => $transaction->amountCents],
                        ['chart_of_account_id' => $mainCard->liability_account_id, 'type' => 'credit', 'amount_cents' => $transaction->amountCents],
                    ],
                ]);
                CreditCardTransaction::query()->create([
                    'wallet_id' => $wallet->id,
                    'credit_card_id' => $targetCardId,
                    'credit_card_invoice_id' => $invoice->id,
                    'expense_account_id' => $wallet->suspense_account_id,
                    'journal_entry_id' => $entry->id,
                    'source' => strtolower($this->parser->format($filename)),
                    'external_id' => $row['external_id'],
                    'import_hash' => $row['import_hash'],
                    'statement_file_hash' => $preview['file_hash'] ?? null,
                    'purchase_date' => $transaction->postedAt,
                    'merchant_name' => $transaction->description,
                    'description' => $transaction->description,
                    'amount_cents' => $transaction->amountCents,
                    'installments_total' => $row['installments_total'],
                    'installment_number' => $row['installment_number'],
                    'status' => 'draft',
                ]);
                $created++;
            }

            $this->invoices->refreshTotals($invoice);

            return ['created' => $created, 'ignored' => $ignored];
        });
    }
}
