<?php

namespace App\Services\Financial;

use App\Models\CreditCardTransaction;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassifyCreditCardPurchase
{
    public function __construct(
        private readonly ValidateCreditCardPurchaseClassificationAccount $validateAccount,
        private readonly EnsureAccountingPeriodIsOpen $ensurePeriodIsOpen,
    ) {}

    public function execute(Wallet $wallet, CreditCardTransaction $transaction, int $accountId): CreditCardTransaction
    {
        return DB::transaction(function () use ($wallet, $transaction, $accountId) {
            $transaction = CreditCardTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->with('journalEntry.lines')
                ->lockForUpdate()
                ->findOrFail($transaction->id);
            $entry = $transaction->journalEntry;

            if (! $entry || $entry->status !== 'draft') {
                throw ValidationException::withMessages([
                    'transaction' => 'Somente compras com lançamento em rascunho podem ser classificadas.',
                ]);
            }

            $this->ensurePeriodIsOpen->handle($wallet, $entry->entry_date);
            $account = $this->validateAccount->execute($wallet, $accountId);
            $mainCard = $transaction->creditCard()->with('parentCard')->firstOrFail();
            $liabilityAccountId = (int) ($mainCard->parentCard?->liability_account_id ?? $mainCard->liability_account_id);
            $debitLines = $entry->lines->filter(fn (JournalLine $line) => $line->type === 'debit');
            $classificationLine = $debitLines->count() === 1 ? $debitLines->first() : null;
            $liabilityLine = $entry->lines->first(fn (JournalLine $line) => $line->type === 'credit'
                && (int) $line->chart_of_account_id === $liabilityAccountId);

            if (! $classificationLine || ! $liabilityLine
                || (int) $classificationLine->amount_cents !== (int) $liabilityLine->amount_cents) {
                throw ValidationException::withMessages([
                    'transaction' => 'O lançamento da compra não possui a estrutura contábil esperada para classificação.',
                ]);
            }

            $classificationLine->update([
                'chart_of_account_id' => $account->id,
                'memo' => 'Classificação da compra no cartão',
            ]);
            $transaction->update(['expense_account_id' => $account->id]);

            return $transaction->fresh(['expenseAccount', 'journalEntry.lines']);
        });
    }
}
