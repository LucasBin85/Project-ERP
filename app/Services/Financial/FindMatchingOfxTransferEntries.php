<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankAccountTransfer;
use App\Models\BankStatementImportTransaction;
use Illuminate\Support\Collection;

class FindMatchingOfxTransferEntries
{
    /** @return Collection<int, BankStatementImportTransaction> */
    public function candidates(BankAccountTransfer $transfer, BankAccount $currentAccount, bool $lockForUpdate = false): Collection
    {
        if ($transfer->validation_status !== 'pending_counterpart_ofx'
            || $transfer->journalEntry?->status !== 'draft'
            || $transfer->journalEntry?->source !== 'ofx'
            || $transfer->journalEntry?->settledAccountPayable()->exists()
            || $transfer->journalEntry?->settledAccountReceivable()->exists()) {
            return collect();
        }

        $counterpartId = (int) $currentAccount->id === (int) $transfer->from_bank_account_id
            ? (int) $transfer->to_bank_account_id
            : (int) $transfer->from_bank_account_id;
        $expectedDirection = $counterpartId === (int) $transfer->from_bank_account_id ? 'out' : 'in';

        return BankStatementImportTransaction::query()
            ->where('wallet_id', $transfer->wallet_id)
            ->where('bank_account_id', $counterpartId)
            ->where('status', 'imported')
            ->whereDate('posted_at', $transfer->transfer_date->toDateString())
            ->where('amount_cents', $transfer->amount_cents)
            ->where('direction', $expectedDirection)
            ->whereNotNull('journal_entry_id')
            ->whereNull('classification_account_id')
            ->where(function ($query) {
                $query->whereNull('operation_type')->orWhere('operation_type', OfxOperationTypePolicy::TRANSFER);
            })
            ->whereHas('journalEntry', function ($query) use ($transfer) {
                $query->where('wallet_id', $transfer->wallet_id)
                    ->where('source', 'ofx')->where('status', 'draft')
                    ->whereDate('entry_date', $transfer->transfer_date->toDateString())
                    ->whereDoesntHave('settledAccountPayable')
                    ->whereDoesntHave('settledAccountReceivable');
            })
            ->with(['journalEntry.lines', 'journalEntry.wallet', 'bankAccount:id,name,chart_of_account_id'])
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get()
            ->filter(function (BankStatementImportTransaction $audit) use ($counterpartId, $expectedDirection) {
                $account = $audit->bankAccount;
                $lines = $audit->journalEntry?->lines ?? collect();
                $bankLine = $lines->firstWhere('chart_of_account_id', $account?->chart_of_account_id);
                $suspenseId = $audit->journalEntry?->wallet?->suspense_account_id;
                $suspenseLine = $lines->firstWhere('chart_of_account_id', $suspenseId);

                return (int) $audit->bank_account_id === $counterpartId
                    && ! BankAccountTransfer::query()->where('journal_entry_id', $audit->journal_entry_id)->exists()
                    && $lines->count() === 2 && $bankLine && $suspenseLine
                    && (int) $bankLine->amount_cents === (int) $audit->amount_cents
                    && $bankLine->type === ($expectedDirection === 'in' ? 'debit' : 'credit')
                    && $bankLine->type !== $suspenseLine->type;
            })->values();
    }
}
