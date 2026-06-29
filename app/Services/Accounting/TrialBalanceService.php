<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class TrialBalanceService
{
    public function generate(
        Wallet $wallet,
        ?string $from = null,
        ?string $to = null
    ): array {
        $accounts = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->orderBy('code')
            ->get();

        $totalsByAccount = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.wallet_id', $wallet->id)
            ->where('journal_entries.status', 'posted')
            ->when($from, function ($query) use ($from) {
                $query->whereDate('journal_entries.entry_date', '>=', $from);
            })
            ->when($to, function ($query) use ($to) {
                $query->whereDate('journal_entries.entry_date', '<=', $to);
            })
            ->select(
                'journal_lines.chart_of_account_id',
                DB::raw("SUM(CASE WHEN journal_lines.type = 'debit' THEN journal_lines.amount_cents ELSE 0 END) as debit_cents"),
                DB::raw("SUM(CASE WHEN journal_lines.type = 'credit' THEN journal_lines.amount_cents ELSE 0 END) as credit_cents"),
            )
            ->groupBy('journal_lines.chart_of_account_id')
            ->get()
            ->keyBy('chart_of_account_id');

        $rows = $accounts
            ->map(function (ChartOfAccount $account) use ($totalsByAccount) {
                $totals = $totalsByAccount->get($account->id);

                $debitCents = (int) ($totals->debit_cents ?? 0);
                $creditCents = (int) ($totals->credit_cents ?? 0);

                $balance = $debitCents - $creditCents;

                return [
                    'account_id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'nature' => $this->accountNature($account->type),
                    'allows_posting' => (bool) $account->allows_posting,

                    'debit_cents' => $debitCents,
                    'credit_cents' => $creditCents,

                    'debit_balance_cents' => $balance > 0 ? $balance : 0,
                    'credit_balance_cents' => $balance < 0 ? abs($balance) : 0,
                ];
            })
            ->filter(function (array $row) {
                return
                    $row['debit_cents'] !== 0 ||
                    $row['credit_cents'] !== 0 ||
                    $row['debit_balance_cents'] !== 0 ||
                    $row['credit_balance_cents'] !== 0;
            })
            ->values();

        $totalDebit = $rows->sum('debit_cents');
        $totalCredit = $rows->sum('credit_cents');
        $totalDebitBalance = $rows->sum('debit_balance_cents');
        $totalCreditBalance = $rows->sum('credit_balance_cents');

        return [
            'rows' => $rows,
            'totals' => [
                'debit_cents' => $totalDebit,
                'credit_cents' => $totalCredit,
                'debit_balance_cents' => $totalDebitBalance,
                'credit_balance_cents' => $totalCreditBalance,
                'difference_cents' => $totalDebit - $totalCredit,
                'balance_difference_cents' => $totalDebitBalance - $totalCreditBalance,
            ],
        ];
    }

    private function accountNature(string $type): string
    {
        return match ($type) {
            'ativo', 'despesa' => 'devedora',
            'passivo', 'receita', 'patrimonio' => 'credora',
            default => '-',
        };
    }
}