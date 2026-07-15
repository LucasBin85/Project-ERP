<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BankAccountBalanceService
{
    /**
     * O saldo operacional segue o Extrato: toda linha bancária draft ou posted.
     * O saldo contábil permanece restrito a lançamentos posted.
     *
     * @return array{statement_balance_cents: int, accounting_balance_cents: int}
     */
    public function calculate(Wallet $wallet, BankAccount $bankAccount): array
    {
        return $this->calculateMany($wallet, collect([$bankAccount]))[$bankAccount->id];
    }

    /**
     * @param  Collection<int, BankAccount>  $bankAccounts
     * @return array<int, array{statement_balance_cents: int, accounting_balance_cents: int}>
     */
    public function calculateMany(Wallet $wallet, Collection $bankAccounts): array
    {
        $bankAccounts->each(function (BankAccount $bankAccount) use ($wallet) {
            if ((int) $bankAccount->wallet_id !== (int) $wallet->id) {
                throw new InvalidArgumentException('A conta bancária não pertence à wallet informada.');
            }
        });

        if ($bankAccounts->isEmpty()) {
            return [];
        }

        $totalsByChartAccount = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.wallet_id', $wallet->id)
            ->whereIn('journal_entries.status', ['draft', 'posted'])
            ->whereIn('journal_lines.chart_of_account_id', $bankAccounts->pluck('chart_of_account_id'))
            ->selectRaw("
                journal_lines.chart_of_account_id,
                SUM(CASE
                    WHEN journal_lines.type = 'debit' THEN journal_lines.amount_cents
                    ELSE -journal_lines.amount_cents
                END) AS statement_balance_cents,
                SUM(CASE
                    WHEN journal_entries.status = 'posted' AND journal_lines.type = 'debit'
                        THEN journal_lines.amount_cents
                    WHEN journal_entries.status = 'posted' AND journal_lines.type = 'credit'
                        THEN -journal_lines.amount_cents
                    ELSE 0
                END) AS accounting_balance_cents
            ")
            ->groupBy('journal_lines.chart_of_account_id')
            ->get()
            ->keyBy('chart_of_account_id');

        return $bankAccounts
            ->mapWithKeys(function (BankAccount $bankAccount) use ($totalsByChartAccount) {
                $totals = $totalsByChartAccount->get($bankAccount->chart_of_account_id);

                return [
                    $bankAccount->id => [
                        'statement_balance_cents' => (int) ($totals->statement_balance_cents ?? 0),
                        'accounting_balance_cents' => (int) ($totals->accounting_balance_cents ?? 0),
                    ],
                ];
            })
            ->all();
    }
}
