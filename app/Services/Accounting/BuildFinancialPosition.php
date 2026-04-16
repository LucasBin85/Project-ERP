<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Wallet;

class BuildFinancialPosition
{
    public function handle(Wallet $wallet): array
    {
        // 1) Buscar contas da wallet com relações
        $accounts = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->with('children')
            ->orderBy('code')
            ->get();

        // 2) Indexar por id
        $accountsById = $accounts->keyBy('id');

        // 3) Calcular saldo próprio de cada conta
        $balances = $this->calculateBalances($accountsById);

        // 4) Montar árvore
        $tree = $this->buildTree($accountsById, $balances);

        // 5) Agrupar por financial_group
        $groups = $this->buildGroups($tree);

        // 6) Summary
        $summary = $this->buildSummary($groups);

        return [
            'summary' => $summary,
            'groups' => $groups,
        ];
    }

    private function calculateBalances($accountsById): array
    {
        $balances = [];

        foreach ($accountsById as $account) {
            $debit = $account->journalLines()
                ->where('type', 'debit')
                ->sum('amount_cents');

            $credit = $account->journalLines()
                ->where('type', 'credit')
                ->sum('amount_cents');

            if ($account->normal_balance === 'debit') {
                $balances[$account->id] = $debit - $credit;
            } else {
                $balances[$account->id] = $credit - $debit;
            }
        }

        return $balances;
    }

    private function buildTree($accountsById, $balances): array
    {
        $nodes = [];

        foreach ($accountsById as $account) {
            $nodes[$account->id] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'allows_posting' => $account->allows_posting,
                'financial_group' => $account->financial_group,
                'own_balance_cents' => $balances[$account->id] ?? 0,
                'total_balance_cents' => 0,
                'children' => [],
                'parent_id' => $account->parent_id,
            ];
        }

        // montar hierarquia
        foreach ($nodes as $id => &$node) {
            if ($node['parent_id']) {
                $nodes[$node['parent_id']]['children'][] = &$node;
            }
        }

        // pegar raízes
        $tree = array_filter($nodes, fn ($n) => $n['parent_id'] === null);

        // calcular totais recursivamente
        foreach ($tree as &$node) {
            $this->calculateTotals($node);
        }

        return $tree;
    }

    private function calculateTotals(array &$node): int
    {
        $total = $node['own_balance_cents'];

        foreach ($node['children'] as &$child) {
            $total += $this->calculateTotals($child);
        }

        $node['total_balance_cents'] = $total;

        return $total;
    }

    private function buildGroups(array $tree): array
    {
        $groupsMap = [
            'available' => 'Disponível',
            'investments' => 'Investimentos',
            'accounts_receivable' => 'Contas a Receber',
            'accounts_payable' => 'Contas a Pagar',
        ];

        $groups = [];

        foreach ($groupsMap as $key => $label) {
            $groups[$key] = [
                'key' => $key,
                'label' => $label,
                'total_cents' => 0,
                'accounts' => [],
            ];
        }

        foreach ($tree as $node) {
            $this->collectGroupRoots($node, $groups);
        }

        return array_values($groups);
    }

    private function collectGroupRoots(array $node, array &$groups, bool $hasParentGroup = false): void
    {
        $hasOwnGroup = !empty($node['financial_group']);

        // 👉 Só adiciona se:
        // - tem grupo
        // - NÃO tem pai com grupo
        if ($hasOwnGroup && !$hasParentGroup && isset($groups[$node['financial_group']])) {

            $groups[$node['financial_group']]['accounts'][] = $node;

            $groups[$node['financial_group']]['total_cents'] += $node['total_balance_cents'];
        }

        foreach ($node['children'] as $child) {
            $this->collectGroupRoots(
                $child,
                $groups,
                $hasParentGroup || $hasOwnGroup // 👈 aqui está o segredo
            );
        }
    }

    private function buildSummary(array $groups): array
    {
        $map = collect($groups)->keyBy('key');

        $available = $map['available']['total_cents'] ?? 0;
        $investments = $map['investments']['total_cents'] ?? 0;
        $receivable = $map['accounts_receivable']['total_cents'] ?? 0;
        $payable = $map['accounts_payable']['total_cents'] ?? 0;

        return [
            'available_cents' => $available,
            'investments_cents' => $investments,
            'accounts_receivable_cents' => $receivable,
            'accounts_payable_cents' => $payable,
            'net_position_cents' => $available + $investments + $receivable - $payable,
        ];
    }
}