<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountBalanceService
{
    /**
     * Retorna o saldo em centavos da conta, considerando o normal_balance:
     * - normal_balance = debit:  saldo = débitos - créditos
     * - normal_balance = credit: saldo = créditos - débitos
     */
    public function getBalanceCents(
        int $chartOfAccountId,
        bool $onlyPosted = true,
        ?string $fromDate = null,
        ?string $toDate = null
    ): int {
        $account = ChartOfAccount::query()->findOrFail($chartOfAccountId);

        $totals = $this->queryLineTotals($account->wallet_id, $onlyPosted, $fromDate, $toDate)
            ->where('journal_lines.chart_of_account_id', $account->id)
            ->selectRaw("
                SUM(CASE WHEN journal_lines.type = 'debit' THEN journal_lines.amount_cents ELSE 0 END) AS debit_cents,
                SUM(CASE WHEN journal_lines.type = 'credit' THEN journal_lines.amount_cents ELSE 0 END) AS credit_cents
            ")
            ->first();

        return $this->normalBalanceAmount(
            $account->normal_balance,
            (int) ($totals->debit_cents ?? 0),
            (int) ($totals->credit_cents ?? 0),
        );
    }

    /**
     * Retorna um mapa [chart_of_account_id => saldo_cents] para uma wallet.
     */
    public function getBalancesByWallet(
        int $walletId,
        bool $onlyPosted = true,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        return $this->getRowsByWallet($walletId, $onlyPosted, $fromDate, $toDate)
            ->mapWithKeys(fn (array $row) => [
                $row['account_id'] => $row['balance_cents'],
            ])
            ->all();
    }

    /**
     * Fonte única para relatórios contábeis.
     *
     * Retorna todas as contas da wallet com débitos, créditos e saldos calculados.
     * O saldo devedor/credor usa a posição contábil bruta: débitos - créditos.
     * O campo balance_cents usa a natureza normal da conta.
     */
    public function getRowsByWallet(
        int|Wallet $wallet,
        bool $onlyPosted = true,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?array $types = null,
        bool $onlyWithMovementOrBalance = false
    ): Collection {
        $walletId = $wallet instanceof Wallet ? $wallet->id : $wallet;

        $accounts = ChartOfAccount::query()
            ->where('wallet_id', $walletId)
            ->when($types, fn ($query) => $query->whereIn('type', $types))
            ->orderBy('code')
            ->get();

        $totalsByAccount = $this->queryLineTotals($walletId, $onlyPosted, $fromDate, $toDate)
            ->selectRaw("
                journal_lines.chart_of_account_id,
                SUM(CASE WHEN journal_lines.type = 'debit' THEN journal_lines.amount_cents ELSE 0 END) AS debit_cents,
                SUM(CASE WHEN journal_lines.type = 'credit' THEN journal_lines.amount_cents ELSE 0 END) AS credit_cents
            ")
            ->groupBy('journal_lines.chart_of_account_id')
            ->get()
            ->keyBy('chart_of_account_id');

        $rows = $accounts->map(function (ChartOfAccount $account) use ($totalsByAccount) {
            $totals = $totalsByAccount->get($account->id);

            $debitCents = (int) ($totals->debit_cents ?? 0);
            $creditCents = (int) ($totals->credit_cents ?? 0);
            $rawBalance = $debitCents - $creditCents;

            return [
                'account_id' => $account->id,
                'parent_id' => $account->parent_id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'nature' => $this->accountNature($account->type),
                'allows_posting' => (bool) $account->allows_posting,

                'debit_cents' => $debitCents,
                'credit_cents' => $creditCents,
                'raw_balance_cents' => $rawBalance,
                'debit_balance_cents' => $rawBalance > 0 ? $rawBalance : 0,
                'credit_balance_cents' => $rawBalance < 0 ? abs($rawBalance) : 0,
                'balance_cents' => $this->normalBalanceAmount($account->normal_balance, $debitCents, $creditCents),
            ];
        });

        if (! $onlyWithMovementOrBalance) {
            return $rows->values();
        }

        return $rows
            ->filter(fn (array $row) =>
                $row['debit_cents'] !== 0 ||
                $row['credit_cents'] !== 0 ||
                $row['debit_balance_cents'] !== 0 ||
                $row['credit_balance_cents'] !== 0
            )
            ->values();
    }

    private function queryLineTotals(
        int $walletId,
        bool $onlyPosted,
        ?string $fromDate,
        ?string $toDate
    ) {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.wallet_id', $walletId)
            ->when($onlyPosted, fn ($query) => $query->where('journal_entries.status', 'posted'))
            ->when($fromDate, fn ($query) => $query->whereDate('journal_entries.entry_date', '>=', $fromDate))
            ->when($toDate, fn ($query) => $query->whereDate('journal_entries.entry_date', '<=', $toDate));
    }

    private function normalBalanceAmount(string $normalBalance, int $debits, int $credits): int
    {
        return match ($normalBalance) {
            'debit' => $debits - $credits,
            'credit' => $credits - $debits,
            default => throw new RuntimeException('normal_balance inválido na conta.'),
        };
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
