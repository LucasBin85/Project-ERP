<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalLine;
use App\Models\Wallet;

class BuildOfxReconciliationStatementItems
{
    public function build(Wallet $wallet, BankAccount $bankAccount, string $periodStart, string $periodEnd, array $availableLineIds = []): array
    {
        $alreadyReconciledIds = BankReconciliationStatementItem::query()
            ->whereNotNull('bank_statement_import_transaction_id')
            ->whereHas('bankReconciliation', function ($query) use ($wallet, $bankAccount) {
                $query->where('wallet_id', $wallet->id)
                    ->where('bank_account_id', $bankAccount->id);
            })
            ->pluck('bank_statement_import_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $availableLineIds = collect($availableLineIds)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->whereDate('posted_at', '>=', $periodStart)
            ->whereDate('posted_at', '<=', $periodEnd)
            ->when($alreadyReconciledIds !== [], fn ($query) => $query->whereNotIn('id', $alreadyReconciledIds))
            ->with(['journalEntry.lines', 'journalLine.journalEntry'])
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get()
            ->map(function (BankStatementImportTransaction $transaction) use ($bankAccount, $availableLineIds) {
                $signedAmount = $transaction->direction === 'in'
                    ? (int) $transaction->amount_cents
                    : -1 * (int) $transaction->amount_cents;

                $matchedLineId = $this->matchedJournalLineId($transaction, $bankAccount, $availableLineIds);

                return [
                    'bank_statement_import_transaction_id' => $transaction->id,
                    'source' => 'ofx',
                    'source_label' => 'OFX',
                    'external_id' => $transaction->external_id,
                    'fit_id' => $transaction->fit_id,
                    'transaction_date' => $transaction->posted_at?->toDateString(),
                    'description' => $transaction->description,
                    'amount_cents' => $signedAmount,
                    'direction' => $transaction->direction,
                    'journal_entry_id' => $transaction->journal_entry_id,
                    'journal_line_id' => $matchedLineId,
                    'match_reason' => $matchedLineId ? 'Match exato por conta, data, valor e direção' : null,
                ];
            })
            ->values()
            ->all();
    }

    private function matchedJournalLineId(BankStatementImportTransaction $transaction, BankAccount $bankAccount, array $availableLineIds): ?int
    {
        $line = $transaction->journalLine
            ?: $transaction->journalEntry?->lines
                ->first(fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $bankAccount->chart_of_account_id);

        $entry = $line?->journalEntry ?: $transaction->journalEntry;

        if (! $entry || ! $line || $entry->status !== 'posted') {
            return null;
        }

        $expectedType = $transaction->direction === 'in' ? 'debit' : 'credit';

        if ((int) $line->chart_of_account_id !== (int) $bankAccount->chart_of_account_id
            || $line->type !== $expectedType
            || (int) $line->amount_cents !== (int) $transaction->amount_cents
            || $entry->entry_date?->toDateString() !== $transaction->posted_at?->toDateString()) {
            return null;
        }

        if (! in_array((int) $line->id, $availableLineIds, true)) {
            return null;
        }

        return (int) $line->id;
    }
}
