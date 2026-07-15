<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\AssessJournalEntryPostingReadiness;
use InvalidArgumentException;

class BankAccountWorkflowSummaryService
{
    public function __construct(
        private readonly AssessJournalEntryPostingReadiness $postingReadiness,
    ) {}

    /**
     * @return array{
     *     unclassified_entries: int,
     *     ready_for_accounting_entries: int,
     *     pending_link_entries: int,
     *     posted_entries: int
     * }
     */
    public function handle(Wallet $wallet, BankAccount $bankAccount): array
    {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id) {
            throw new InvalidArgumentException('A conta bancária deve pertencer à wallet ativa.');
        }

        $entries = JournalEntry::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('status', ['draft', 'posted'])
            ->whereHas('lines', fn ($query) => $query
                ->where('chart_of_account_id', $bankAccount->chart_of_account_id))
            ->with([
                'lines.chartOfAccount.children',
                'settledAccountPayable:id,payment_journal_entry_id',
                'settledAccountReceivable:id,receipt_journal_entry_id',
            ])
            ->get();

        $auditsByEntry = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->whereIn('journal_entry_id', $entries->pluck('id'))
            ->where('status', 'imported')
            ->orderByDesc('id')
            ->get(['id', 'journal_entry_id', 'operation_type', 'direction'])
            ->unique('journal_entry_id')
            ->keyBy('journal_entry_id');

        $summary = [
            'unclassified_entries' => 0,
            'ready_for_accounting_entries' => 0,
            'pending_link_entries' => 0,
            'posted_entries' => 0,
        ];

        foreach ($entries as $entry) {
            if ($entry->status === 'posted') {
                $summary['posted_entries']++;

                continue;
            }

            $audit = $auditsByEntry->get($entry->id);

            if ($audit?->operation_type === OfxOperationTypePolicy::PAYMENT
                && $audit?->direction === OfxOperationTypePolicy::DIRECTION_OUT
                && ! $entry->settledAccountPayable) {
                $summary['pending_link_entries']++;

                continue;
            }

            if ($audit?->operation_type === OfxOperationTypePolicy::INCOME
                && $audit?->direction === OfxOperationTypePolicy::DIRECTION_IN
                && $wallet->suspense_account_id
                && $entry->lines->contains(fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id)
                && ! $entry->settledAccountReceivable) {
                $summary['pending_link_entries']++;

                continue;
            }

            if ($wallet->suspense_account_id && $entry->lines->contains(
                fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id,
            )) {
                $summary['unclassified_entries']++;

                continue;
            }

            if ($this->postingReadiness->handle($wallet, $entry)->ready) {
                $summary['ready_for_accounting_entries']++;
            }
        }

        return $summary;
    }
}
