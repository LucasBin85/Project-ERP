<?php

namespace App\Services\Accounting;

use App\Data\Accounting\IncomeStatementData;
use App\Models\ChartOfAccount;
use App\Models\Wallet;
use App\Services\Accounting\Reports\ReportTreeBuilder;

class IncomeStatementService
{
    public function __construct(
        private readonly ReportTreeBuilder $treeBuilder,
    ) {}

    public function build(Wallet $wallet, ?string $startDate = null, ?string $endDate = null): IncomeStatementData
    {
        $accounts = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('type', ['receita', 'despesa'])
            ->with(['journalLines' => function ($query) use ($startDate, $endDate) {
                $query->whereHas('journalEntry', function ($entryQuery) use ($startDate, $endDate) {
                    $entryQuery->where('status', 'posted');

                    if ($startDate) {
                        $entryQuery->whereDate('entry_date', '>=', $startDate);
                    }

                    if ($endDate) {
                        $entryQuery->whereDate('entry_date', '<=', $endDate);
                    }
                });
            }])
            ->orderBy('code')
            ->get();

        $rows = $accounts->map(function ($account) {
            $debit = $account->journalLines
                ->where('type', 'debit')
                ->sum('amount_cents');

            $credit = $account->journalLines
                ->where('type', 'credit')
                ->sum('amount_cents');

            $amount = match ($account->type) {
                'receita' => $credit - $debit,
                'despesa' => $debit - $credit,
                default => 0,
            };

            return [
                'account_id' => $account->id,
                'parent_id' => $account->parent_id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'allows_posting' => (bool) $account->allows_posting,
                'amount_cents' => $amount,
            ];
        });

        $revenues = $this->treeBuilder->build($rows, 'receita', 'Receitas', 'amount_cents');
        $expenses = $this->treeBuilder->build($rows, 'despesa', 'Despesas', 'amount_cents');

        return new IncomeStatementData(
            revenues: $revenues,
            expenses: $expenses,
        );
    }
}