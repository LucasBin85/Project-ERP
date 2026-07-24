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

    public function execute(Wallet $wallet, CreditCard $card, array $preview, string $contents, string $filename, array $decisions): array
    {
        if ((int) $card->wallet_id !== (int) $wallet->id || ! $wallet->suspense_account_id) {
            throw ValidationException::withMessages(['statement_import' => 'Cartão ou conta A classificar inválidos para esta importação.']);
        }

        return DB::transaction(function () use ($wallet, $card, $preview, $contents, $filename, $decisions) {
            $parsed = $this->parser->parse($contents, $filename);
            $decisionMap = collect($decisions)->keyBy('row_key');
            $created = 0;
            $ignored = 0;
            $invoiceIds = [];

            foreach ($preview['rows'] as $row) {
                $decision = $decisionMap->get($row['row_key']);
                if (($decision['action'] ?? 'ignore') !== 'create' || $row['situation'] !== 'new') {
                    $ignored++;

                    continue;
                }
                if (CreditCardTransaction::query()->where('credit_card_id', $card->id)
                    ->where(fn ($query) => $query->where('import_hash', $row['import_hash'])->orWhere('external_id', $row['external_id']))->exists()) {
                    $ignored++;

                    continue;
                }

                $transaction = $parsed['transactions'][$row['index']];
                $invoice = $this->invoices->forPurchaseDate($wallet, $card, $transaction->postedAt);
                $mainCard = $this->invoices->mainCard($card);
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
                    'credit_card_id' => $card->id,
                    'credit_card_invoice_id' => $invoice->id,
                    'expense_account_id' => $wallet->suspense_account_id,
                    'journal_entry_id' => $entry->id,
                    'source' => strtolower($this->parser->format($filename)),
                    'external_id' => $row['external_id'],
                    'import_hash' => $row['import_hash'],
                    'purchase_date' => $transaction->postedAt,
                    'merchant_name' => $transaction->description,
                    'description' => $transaction->description,
                    'amount_cents' => $transaction->amountCents,
                    'installments_total' => $row['installments_total'],
                    'installment_number' => $row['installment_number'],
                    'status' => 'draft',
                ]);
                $invoiceIds[] = $invoice->id;
                $created++;
            }

            collect($invoiceIds)->unique()->each(fn ($id) => $this->invoices->refreshTotals(
                \App\Models\CreditCardInvoice::query()->findOrFail($id)
            ));

            return ['created' => $created, 'ignored' => $ignored];
        });
    }
}
