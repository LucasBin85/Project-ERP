<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountBalanceService
{
    /**
     * Retorna o saldo em centavos da conta, considerando o normal_balance:
     * - Se normal_balance = debit:  saldo = debits - credits
     * - Se normal_balance = credit: saldo = credits - debits
     *
     * $onlyPosted = true => considera apenas journal_entries.status = posted
     * $fromDate/$toDate (YYYY-MM-DD) opcionais
     */
    public function getBalanceCents(
        int $chartOfAccountId,
        bool $onlyPosted = true,
        ?string $fromDate = null,
        ?string $toDate = null
    ): int {
        $account = ChartOfAccount::query()->findOrFail($chartOfAccountId);

        $q = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.chart_of_account_id', $account->id)
            ->where('je.wallet_id', $account->wallet_id);

        if ($onlyPosted) {
            $q->where('je.status', 'posted');
        }

        if ($fromDate) {
            $q->whereDate('je.entry_date', '>=', $fromDate);
        }

        if ($toDate) {
            $q->whereDate('je.entry_date', '<=', $toDate);
        }

        $debits = (int) (clone $q)->where('jl.type', 'debit')->sum('jl.amount_cents');
        $credits = (int) (clone $q)->where('jl.type', 'credit')->sum('jl.amount_cents');

        if ($account->normal_balance === 'debit') {
            return $debits - $credits;
        }

        if ($account->normal_balance === 'credit') {
            return $credits - $debits;
        }

        throw new RuntimeException('normal_balance inválido na conta.');
    }

    /**
     * Retorna um mapa [chart_of_account_id => saldo_cents] para uma wallet.
     * Útil para dashboard/balancete.
     */
    public function getBalancesByWallet(
        int $walletId,
        bool $onlyPosted = true,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $q = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.chart_of_account_id')
            ->where('je.wallet_id', $walletId);

        if ($onlyPosted) {
            $q->where('je.status', 'posted');
        }

        if ($fromDate) {
            $q->whereDate('je.entry_date', '>=', $fromDate);
        }

        if ($toDate) {
            $q->whereDate('je.entry_date', '<=', $toDate);
        }

        // Soma debits e credits por conta
        $rows = $q->selectRaw("
                jl.chart_of_account_id,
                coa.normal_balance,
                SUM(CASE WHEN jl.type = 'debit'  THEN jl.amount_cents ELSE 0 END) AS debits,
                SUM(CASE WHEN jl.type = 'credit' THEN jl.amount_cents ELSE 0 END) AS credits
            ")
            ->groupBy('jl.chart_of_account_id', 'coa.normal_balance')
            ->get();

        $balances = [];

        foreach ($rows as $r) {
            $debits = (int) $r->debits;
            $credits = (int) $r->credits;

            if ($r->normal_balance === 'debit') {
                $balances[(int) $r->chart_of_account_id] = $debits - $credits;
            } else {
                $balances[(int) $r->chart_of_account_id] = $credits - $debits;
            }
        }

        return $balances;
    }
}