<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Models\Wallet;

class BuildBankStatementClosingSummary
{
    public function __construct(private readonly BankStatementService $statements) {}

    public function execute(Wallet $wallet, BankAccount $bankAccount, string $startDate, string $endDate): array
    {
        abort_unless((int) $bankAccount->wallet_id === (int) $wallet->id, 404);
        $statement = $this->statements->build($wallet, new BankStatementFiltersDTO($bankAccount->id, $startDate, $endDate));
        $transactions = $statement->transactions;
        $accountingBalance = $this->accountingBalanceAt($wallet, $bankAccount, $endDate);

        $counts = [
            'pending_classification' => $transactions->where('workflow_status', 'pending_classification')->count(),
            'pending_links' => $transactions->where('workflow_status', 'pending_link')->count(),
            'pending_transfers' => $transactions->filter(fn ($item) => ($item['transfer']['status'] ?? null) === 'pending_counterpart_ofx')->count(),
            'investments' => $transactions->where('operation_type', OfxOperationTypePolicy::INVESTMENT)->where('classification_status', 'classified')->count(),
            'ready_for_accounting' => $transactions->where('workflow_status', 'ready_for_accounting')->count(),
            'posted' => $transactions->where('workflow_status', 'posted')->count(),
            'inconsistencies' => $transactions->where('workflow_status', 'classified')->count(),
            'applicable_suggestions' => $transactions->filter(fn ($item) => (bool) ($item['classification_suggestion']['can_bulk_apply'] ?? false))->count(),
        ];
        $hasIncomplete = $counts['pending_classification'] + $counts['pending_links'] + $counts['pending_transfers'] + $counts['inconsistencies'] > 0;
        $total = $transactions->count();
        $status = match (true) {
            $counts['posted'] > 0 && ($hasIncomplete || $counts['ready_for_accounting'] > 0) => 'partially_posted',
            $hasIncomplete || $total === 0 => 'incomplete',
            $counts['ready_for_accounting'] > 0 => 'ready_for_accounting',
            $counts['posted'] === $total => 'closed',
            default => 'incomplete',
        };

        $problems = $transactions->filter(fn ($item) => in_array($item['workflow_status'], ['pending_classification', 'pending_link', 'classified'], true)
            || ($item['transfer']['status'] ?? null) === 'pending_counterpart_ofx')->map(fn ($item) => [
                'journal_entry_id' => $item['journal_entry_id'], 'date' => $item['date'], 'description' => $item['description'],
                'amount_cents' => $item['amount_cents'], 'status' => ($item['transfer']['status'] ?? null) === 'pending_counterpart_ofx' ? 'awaiting_counterpart_ofx' : $item['workflow_status'],
                'journal_entry_url' => route('journal-entries.show', $item['journal_entry_id']),
            ])->values()->all();

        return [
            'status' => $status, 'status_label' => $this->statusLabel($status),
            'bank_account' => ['id' => $bankAccount->id, 'name' => $bankAccount->name],
            'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            'balances' => [
                'opening_operational_cents' => $statement->openingBalanceCents,
                'inflows_cents' => $statement->totalInflowsCents, 'outflows_cents' => $statement->totalOutflowsCents,
                'closing_operational_cents' => $statement->closingBalanceCents,
                'posted_accounting_cents' => $accountingBalance,
                'difference_cents' => $statement->closingBalanceCents - $accountingBalance,
            ],
            'counts' => $counts, 'problems' => $problems,
            'items' => $transactions->map(fn ($item) => [
                'journal_entry_id' => $item['journal_entry_id'], 'date' => $item['date'], 'description' => $item['description'], 'amount_cents' => $item['amount_cents'],
                'workflow_status' => $item['workflow_status'], 'operation_type' => $item['operation_type'],
                'transfer_status' => $item['transfer']['status'] ?? null,
                'category' => match (true) {
                    ($item['transfer']['status'] ?? null) === 'pending_counterpart_ofx' => 'pending_transfers',
                    $item['workflow_status'] === 'pending_classification' => 'pending_classification',
                    $item['workflow_status'] === 'pending_link' => 'pending_links',
                    $item['workflow_status'] === 'ready_for_accounting' => 'ready_for_accounting',
                    $item['workflow_status'] === 'posted' => 'posted',
                    $item['operation_type'] === OfxOperationTypePolicy::INVESTMENT => 'investments',
                    default => 'inconsistencies',
                },
                'journal_entry_url' => route('journal-entries.show', $item['journal_entry_id']),
            ])->values()->all(),
            'ready_entry_ids' => $transactions->where('workflow_status', 'ready_for_accounting')->pluck('journal_entry_id')->filter()->values()->all(),
            'suggestion_items' => $transactions->filter(fn ($item) => (bool) ($item['classification_suggestion']['can_bulk_apply'] ?? false))->map(fn ($item) => [
                'journal_entry_id' => $item['journal_entry_id'], 'rule_id' => $item['classification_suggestion']['rule_id'] ?? null,
                'suggestion_key' => $item['classification_suggestion']['suggestion_key'] ?? null,
            ])->values()->all(),
        ];
    }

    private function accountingBalanceAt(Wallet $wallet, BankAccount $bankAccount, string $endDate): int
    {
        return (int) JournalLine::query()->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', fn ($query) => $query->where('wallet_id', $wallet->id)->where('status', 'posted')->whereDate('entry_date', '<=', $endDate))
            ->get(['type', 'amount_cents'])->sum(fn ($line) => $line->type === 'debit' ? $line->amount_cents : -$line->amount_cents);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ready_for_accounting' => 'Pronto para contabilizar', 'partially_posted' => 'Parcialmente postado', 'closed' => 'Fechado', default => 'Incompleto'
        };
    }
}
