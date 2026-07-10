<?php

namespace App\Services\Financial;

use App\DTOs\Financial\DashboardFiltersDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\CreditCardInvoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildFinancialDashboard
{
    public function handle(Wallet $wallet, DashboardFiltersDTO $filters): array
    {
        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->with('chartOfAccount:id,code,name')
            ->orderBy('name')
            ->get();

        $bankBalances = $bankAccounts
            ->map(function (BankAccount $account) use ($wallet, $filters) {
                $balance = $this->bankBalanceUntil($wallet, $account, $filters->endDate);

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'bank_name' => $account->bank_name,
                    'account_type' => $account->account_type,
                    'balance_cents' => $balance,
                ];
            })
            ->values();

        $cashBalanceCents = (int) $bankBalances->sum('balance_cents');
        $realized = $this->realizedBankMovements($wallet, $bankAccounts, $filters);
        $projected = $this->projectedMovements($wallet, $filters);
        $kpis = $this->accountingKpis($wallet, $filters);

        return [
            'filters' => $filters->toArray(),
            'kpis' => array_merge($kpis, [
                'cash_balance_cents' => $cashBalanceCents,
                'realized_inflow_cents' => $realized['inflow_cents'],
                'realized_outflow_cents' => $realized['outflow_cents'],
                'realized_net_cents' => $realized['net_cents'],
                'projected_inflow_cents' => $projected['inflow_cents'],
                'projected_outflow_cents' => $projected['outflow_cents'],
                'projected_net_cents' => $projected['net_cents'],
                'projected_cash_balance_cents' => $cashBalanceCents + $projected['net_cents'],
                'overdue_inflow_cents' => $projected['overdue_inflow_cents'],
                'overdue_outflow_cents' => $projected['overdue_outflow_cents'],
            ]),
            'chart' => $this->dailyRevenueExpenseChart($wallet, $filters),
            'latestEntries' => $this->latestEntries($wallet, $filters),
            'bankBalances' => $bankBalances,
            'upcoming' => $projected['upcoming'],
            'alerts' => $this->alerts($projected, $cashBalanceCents),
        ];
    }

    private function accountingKpis(Wallet $wallet, DashboardFiltersDTO $filters): array
    {
        $baseLines = JournalLine::query()
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $filters->startDate)
                    ->whereDate('entry_date', '<=', $filters->endDate);
            });

        $revenueCents = (clone $baseLines)
            ->where('type', 'credit')
            ->whereHas('chartOfAccount', fn ($query) => $query->where('type', 'receita'))
            ->sum('amount_cents');

        $expenseCents = (clone $baseLines)
            ->where('type', 'debit')
            ->whereHas('chartOfAccount', fn ($query) => $query->where('type', 'despesa'))
            ->sum('amount_cents');

        $assetDebit = (clone $baseLines)
            ->where('type', 'debit')
            ->whereHas('chartOfAccount', fn ($query) => $query->where('type', 'ativo'))
            ->sum('amount_cents');

        $assetCredit = (clone $baseLines)
            ->where('type', 'credit')
            ->whereHas('chartOfAccount', fn ($query) => $query->where('type', 'ativo'))
            ->sum('amount_cents');

        $balanceCents = (int) $assetDebit - (int) $assetCredit;

        return [
            'balance_cents' => $balanceCents,
            'revenue_cents' => (int) $revenueCents,
            'expense_cents' => (int) $expenseCents,
            'result_cents' => (int) $revenueCents - (int) $expenseCents,
        ];
    }

    private function dailyRevenueExpenseChart(Wallet $wallet, DashboardFiltersDTO $filters): Collection
    {
        $chartRevenue = JournalLine::query()
            ->selectRaw('DATE(journal_entries.entry_date) as date, SUM(journal_lines.amount_cents) as total')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.chart_of_account_id')
            ->where('journal_entries.wallet_id', $wallet->id)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $filters->startDate)
            ->whereDate('journal_entries.entry_date', '<=', $filters->endDate)
            ->where('journal_lines.type', 'credit')
            ->where('chart_of_accounts.type', 'receita')
            ->groupByRaw('DATE(journal_entries.entry_date)')
            ->get()
            ->keyBy('date');

        $chartExpense = JournalLine::query()
            ->selectRaw('DATE(journal_entries.entry_date) as date, SUM(journal_lines.amount_cents) as total')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.chart_of_account_id')
            ->where('journal_entries.wallet_id', $wallet->id)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $filters->startDate)
            ->whereDate('journal_entries.entry_date', '<=', $filters->endDate)
            ->where('journal_lines.type', 'debit')
            ->where('chart_of_accounts.type', 'despesa')
            ->groupByRaw('DATE(journal_entries.entry_date)')
            ->get()
            ->keyBy('date');

        $dates = collect();
        $cursor = Carbon::parse($filters->startDate);
        $end = Carbon::parse($filters->endDate);

        while ($cursor->lte($end)) {
            $dates->push($cursor->toDateString());
            $cursor->addDay();
        }

        return $dates->map(fn (string $date) => [
            'date' => $date,
            'revenue_cents' => (int) ($chartRevenue[$date]->total ?? 0),
            'expense_cents' => (int) ($chartExpense[$date]->total ?? 0),
        ]);
    }

    private function latestEntries(Wallet $wallet, DashboardFiltersDTO $filters): Collection
    {
        return JournalEntry::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'posted')
            ->whereDate('entry_date', '>=', $filters->startDate)
            ->whereDate('entry_date', '<=', $filters->endDate)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'entry_date', 'description', 'source', 'status'])
            ->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->entry_date,
                'entry_label' => 'JE-' . str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT),
                'entry_show_url' => route('journal-entries.show', $entry),
                'description' => $entry->description,
                'source' => $entry->source,
                'status' => $entry->status,
            ]);
    }

    private function bankBalanceUntil(Wallet $wallet, BankAccount $bankAccount, string $endDate): int
    {
        $lines = JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $endDate) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '<=', $endDate);
            })
            ->get(['type', 'amount_cents']);

        return $lines->reduce(function (int $balance, JournalLine $line) {
            return $line->type === 'debit'
                ? $balance + (int) $line->amount_cents
                : $balance - (int) $line->amount_cents;
        }, 0);
    }

    private function realizedBankMovements(Wallet $wallet, Collection $bankAccounts, DashboardFiltersDTO $filters): array
    {
        $bankChartAccountIds = $bankAccounts->pluck('chart_of_account_id')->filter()->all();

        if ($bankChartAccountIds === []) {
            return [
                'inflow_cents' => 0,
                'outflow_cents' => 0,
                'net_cents' => 0,
            ];
        }

        $lines = JournalLine::query()
            ->whereIn('chart_of_account_id', $bankChartAccountIds)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $filters->startDate)
                    ->whereDate('entry_date', '<=', $filters->endDate);
            })
            ->get(['type', 'amount_cents']);

        $inflow = (int) $lines->where('type', 'debit')->sum('amount_cents');
        $outflow = (int) $lines->where('type', 'credit')->sum('amount_cents');

        return [
            'inflow_cents' => $inflow,
            'outflow_cents' => $outflow,
            'net_cents' => $inflow - $outflow,
        ];
    }

    private function projectedMovements(Wallet $wallet, DashboardFiltersDTO $filters): array
    {
        $today = now()->toDateString();

        $receivables = AccountReceivable::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'pending')
            ->whereDate('due_date', '>=', $filters->startDate)
            ->whereDate('due_date', '<=', $filters->endDate)
            ->orderBy('due_date')
            ->get();

        $payables = AccountPayable::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'pending')
            ->whereDate('due_date', '>=', $filters->startDate)
            ->whereDate('due_date', '<=', $filters->endDate)
            ->orderBy('due_date')
            ->get();

        $invoices = CreditCardInvoice::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('status', ['open', 'closed', 'partial', 'overdue'])
            ->whereDate('due_at', '>=', $filters->startDate)
            ->whereDate('due_at', '<=', $filters->endDate)
            ->with('creditCard:id,name')
            ->orderBy('due_at')
            ->get();

        $upcoming = collect()
            ->merge($receivables->map(fn (AccountReceivable $item) => [
                'id' => 'receivable-' . $item->id,
                'date' => $item->due_date,
                'description' => $item->description,
                'person' => $item->customer_name,
                'type' => 'inflow',
                'source' => 'Conta a receber',
                'amount_cents' => (int) $item->amount_cents,
                'is_overdue' => $item->due_date->toDateString() < $today,
                'url' => route('accounts-receivable.show', $item),
            ]))
            ->merge($payables->map(fn (AccountPayable $item) => [
                'id' => 'payable-' . $item->id,
                'date' => $item->due_date,
                'description' => $item->description,
                'person' => $item->payee_name,
                'type' => 'outflow',
                'source' => 'Conta a pagar',
                'amount_cents' => (int) $item->amount_cents,
                'is_overdue' => $item->due_date->toDateString() < $today,
                'url' => route('accounts-payable.show', $item),
            ]))
            ->merge($invoices->map(fn (CreditCardInvoice $invoice) => [
                'id' => 'card-invoice-' . $invoice->id,
                'date' => $invoice->due_at,
                'description' => sprintf('Fatura %02d/%d', $invoice->reference_month, $invoice->reference_year),
                'person' => $invoice->creditCard?->name ?? 'Cartão de crédito',
                'type' => 'outflow',
                'source' => 'Fatura de cartão',
                'amount_cents' => (int) $invoice->balance_cents,
                'is_overdue' => $invoice->due_at->toDateString() < $today,
                'url' => route('credit-cards.show', $invoice->credit_card_id),
            ]))
            ->sortBy('date')
            ->values();

        $inflow = (int) $receivables->sum('amount_cents');
        $payableOutflow = (int) $payables->sum('amount_cents');
        $invoiceOutflow = (int) $invoices->sum('balance_cents');
        $outflow = $payableOutflow + $invoiceOutflow;

        return [
            'inflow_cents' => $inflow,
            'outflow_cents' => $outflow,
            'net_cents' => $inflow - $outflow,
            'overdue_inflow_cents' => (int) $upcoming->where('type', 'inflow')->where('is_overdue', true)->sum('amount_cents'),
            'overdue_outflow_cents' => (int) $upcoming->where('type', 'outflow')->where('is_overdue', true)->sum('amount_cents'),
            'upcoming' => $upcoming->take(10)->values(),
        ];
    }

    private function alerts(array $projected, int $cashBalanceCents): array
    {
        $alerts = [];

        if ($projected['overdue_inflow_cents'] > 0) {
            $alerts[] = [
                'tone' => 'yellow',
                'title' => 'Recebimentos vencidos',
                'message' => 'Existem contas a receber vencidas no período filtrado.',
                'amount_cents' => $projected['overdue_inflow_cents'],
            ];
        }

        if ($projected['overdue_outflow_cents'] > 0) {
            $alerts[] = [
                'tone' => 'red',
                'title' => 'Pagamentos vencidos',
                'message' => 'Existem contas/faturas vencidas no período filtrado.',
                'amount_cents' => $projected['overdue_outflow_cents'],
            ];
        }

        if ($cashBalanceCents + $projected['net_cents'] < 0) {
            $alerts[] = [
                'tone' => 'red',
                'title' => 'Saldo projetado negativo',
                'message' => 'O caixa projetado fica negativo considerando os vencimentos do período.',
                'amount_cents' => $cashBalanceCents + $projected['net_cents'],
            ];
        }

        return $alerts;
    }
}
