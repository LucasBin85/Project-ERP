<?php

namespace App\Services\Financial;

use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use App\Models\Wallet;

class BuildManagerialFinancialDashboard
{
    public function __construct(private readonly BuildMonthlyWalletClosingSummary $monthlyClosing) {}

    public function execute(Wallet $wallet, int $year, int $month): array
    {
        $closing = $this->monthlyClosing->execute($wallet, $year, $month);
        $end = $closing['period']['end_date'];
        $investmentAccountIds = ChartOfAccount::query()->where('wallet_id', $wallet->id)
            ->where('financial_group', 'investments')->where('allows_posting', true)->pluck('id');
        $investments = $this->balanceUntil($wallet, $investmentAccountIds->all(), $end);
        $formalClosed = $closing['formal_closing']['status'] === 'closed';

        return [
            'period' => $closing['period'],
            'cards' => [
                'bank_operational_cents' => $closing['summary']['closing_operational_cents'],
                'inflows_cents' => $closing['summary']['inflows_cents'],
                'outflows_cents' => $closing['summary']['outflows_cents'],
                'net_result_cents' => $closing['summary']['net_cash_change_cents'],
                'payables_open_cents' => $closing['payables']['open']['amount_cents'],
                'receivables_open_cents' => $closing['receivables']['open']['amount_cents'],
                'investments_cents' => $investments,
                'accounting_pending_count' => $closing['summary']['accounting_pending_count'],
            ],
            'closing' => [
                'status' => $formalClosed ? 'formally_closed' : $closing['status'],
                'status_label' => $formalClosed ? 'Fechado formalmente' : $closing['status_label'],
                'formal_status' => $closing['formal_closing']['status'],
                'url' => route('monthly-closing.show', ['year' => $year, 'month' => $month]),
            ],
            'banks' => collect($closing['banks'])->map(fn (array $bank) => [
                'id' => $bank['id'], 'name' => $bank['name'], 'bank_name' => $bank['bank_name'],
                'operational_cents' => $bank['balances']['closing_operational_cents'],
                'accounting_cents' => $bank['balances']['posted_accounting_cents'],
                'difference_cents' => $bank['balances']['difference_cents'],
                'statement_url' => $bank['statement_url'], 'closing_url' => $bank['closing_url'],
            ])->all(),
            'payables' => [
                'overdue' => $closing['payables']['overdue'],
                'upcoming' => $this->subtract($closing['payables']['open'], $closing['payables']['overdue']),
                'paid' => $closing['payables']['paid'], 'url' => $closing['payables']['url'],
            ],
            'receivables' => [
                'overdue' => $closing['receivables']['overdue'],
                'expected' => $this->subtract($closing['receivables']['open'], $closing['receivables']['overdue']),
                'received' => $closing['receivables']['received'], 'url' => $closing['receivables']['url'],
            ],
            'rankings' => [
                'expenses' => $this->ranking($wallet, $closing['period']['start_date'], $end, 'despesa', 'debit'),
                'revenues' => $this->ranking($wallet, $closing['period']['start_date'], $end, 'receita', 'credit'),
            ],
            'investments' => ['balance_cents' => $investments, 'accounts_count' => $investmentAccountIds->count(), 'url' => route('financial-position.index')],
            'attention' => $this->attention($closing),
        ];
    }

    private function balanceUntil(Wallet $wallet, array $accountIds, string $end): int
    {
        if ($accountIds === []) {
            return 0;
        }

        return (int) JournalLine::query()->whereIn('chart_of_account_id', $accountIds)
            ->whereHas('journalEntry', fn ($query) => $query->where('wallet_id', $wallet->id)->where('status', 'posted')->whereDate('entry_date', '<=', $end))
            ->get(['type', 'amount_cents'])->sum(fn ($line) => $line->type === 'debit' ? $line->amount_cents : -$line->amount_cents);
    }

    private function ranking(Wallet $wallet, string $start, string $end, string $accountType, string $lineType): array
    {
        return JournalLine::query()->selectRaw('chart_of_accounts.id, chart_of_accounts.code, chart_of_accounts.name, SUM(journal_lines.amount_cents) total_cents')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.chart_of_account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.wallet_id', $wallet->id)->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$start, $end])->where('chart_of_accounts.type', $accountType)
            ->where('journal_lines.type', $lineType)->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name')
            ->orderByDesc('total_cents')->limit(5)->get()->map(fn ($row) => [
                'id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'amount_cents' => (int) $row->total_cents,
            ])->all();
    }

    private function attention(array $closing): array
    {
        $bankCounts = collect($closing['banks'])->pluck('counts');

        return [
            ['label' => 'Pendentes de classificação', 'count' => (int) $bankCounts->sum('pending_classification'), 'url' => route('ofx-imports.index')],
            ['label' => 'Vínculos AP/AR pendentes', 'count' => (int) $bankCounts->sum('pending_links'), 'url' => route('bank-accounts.index')],
            ['label' => 'Transferências aguardando contraparte', 'count' => (int) $bankCounts->sum('pending_transfers'), 'url' => route('bank-transfers.index')],
            ['label' => 'Drafts prontos para postar', 'count' => $closing['accounting']['draft_ready'], 'url' => $closing['links']['pending']],
            ['label' => 'Mês não fechado formalmente', 'count' => $closing['formal_closing']['status'] === 'closed' ? 0 : 1, 'url' => route('monthly-closing.show', ['year' => $closing['period']['year'], 'month' => $closing['period']['month']])],
        ];
    }

    private function subtract(array $total, array $part): array
    {
        return ['count' => max(0, $total['count'] - $part['count']), 'amount_cents' => max(0, $total['amount_cents'] - $part['amount_cents'])];
    }
}
