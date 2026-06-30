<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Wallet;

class IncomeStatementService
{
    public function build(Wallet $wallet, ?string $startDate = null, ?string $endDate = null): array
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
                'allows_posting' => $account->allows_posting,
                'amount_cents' => $amount,
            ];
        });

        $revenues = $this->buildSection($rows, 'receita', 'Receitas');
        $expenses = $this->buildSection($rows, 'despesa', 'Despesas');

        $totalRevenue = $revenues['total_cents'];
        $totalExpense = $expenses['total_cents'];
        $netIncome = $totalRevenue - $totalExpense;

        return [
            'sections' => [
                $revenues,
                $expenses,
            ],
            'totals' => [
                'revenue_cents' => $totalRevenue,
                'expense_cents' => $totalExpense,
                'net_income_cents' => $netIncome,
            ],
        ];
    }

    private function buildSection($rows, string $type, string $title): array
    {
        $sectionRows = $rows
            ->where('type', $type)
            ->values();

        $childrenByParent = $sectionRows->groupBy('parent_id');

        $rootRows = $sectionRows
            ->filter(fn ($row) => blank($row['parent_id']) || ! $sectionRows->contains('account_id', $row['parent_id']))
            ->sortBy('code')
            ->values();

        $flattened = [];

        foreach ($rootRows as $row) {
            $this->appendRow($row, $childrenByParent, $flattened);
        }

        $filtered = collect($flattened)
            ->filter(fn ($row) => $row['amount_cents'] !== 0)
            ->values();

        return [
            'key' => $type,
            'title' => $title,
            'total_cents' => $filtered
                ->where('level', 0)
                ->sum('amount_cents'),
            'rows' => $filtered,
        ];
    }

    private function appendRow(array $row, $childrenByParent, array &$flattened, int $level = 0): int
    {
        $children = $childrenByParent
            ->get($row['account_id'], collect())
            ->sortBy('code')
            ->values();

        $currentIndex = count($flattened);

        $flattened[] = [
            ...$row,
            'level' => $level,
            'amount_cents' => $row['amount_cents'],
            'is_summary' => $children->isNotEmpty(),
        ];

        $childrenTotal = 0;

        foreach ($children as $child) {
            $childrenTotal += $this->appendRow($child, $childrenByParent, $flattened, $level + 1);
        }

        $total = $row['amount_cents'] + $childrenTotal;

        $flattened[$currentIndex]['amount_cents'] = $total;

        return $total;
    }
}