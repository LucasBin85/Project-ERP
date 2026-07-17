<?php

namespace App\Services\Financial;

use App\Models\ChartOfAccount;
use App\Models\Supplier;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSupplier
{
    public function execute(Wallet $wallet, array $data): Supplier
    {
        return DB::transaction(function () use ($wallet, $data) {
            $payableAccountId = $data['payable_account_id'] ?? null;
            $expenseAccountId = $data['default_expense_account_id'] ?? null;

            if (! $payableAccountId) {
                $payableAccountId = $this->createPostingAccount(
                    $wallet,
                    '2.1',
                    $data['name'],
                    'passivo',
                    'accounts_payable',
                )->id;
            }

            if (! $expenseAccountId) {
                $expenseAccountId = $this->createPostingAccount(
                    $wallet,
                    '5.1',
                    ($data['default_expense_name'] ?? null) ?: $data['name'],
                    'despesa',
                )->id;
            }

            return Supplier::query()->create([
                'wallet_id' => $wallet->id,
                'name' => $data['name'],
                'document' => $data['document'] ?? null,
                'payable_account_id' => $payableAccountId,
                'default_expense_account_id' => $expenseAccountId,
                'active' => $data['active'] ?? true,
            ]);
        });
    }

    private function createPostingAccount(Wallet $wallet, string $parentCode, string $name, string $type, ?string $financialGroup = null): ChartOfAccount
    {
        $parent = $wallet->chartOfAccounts()->where('code', $parentCode)->firstOrFail();
        $lastSegment = (int) Str::afterLast((string) $wallet->chartOfAccounts()
            ->where('parent_id', $parent->id)->orderByRaw('LENGTH(code) desc')->orderByDesc('code')->value('code'), '.');

        return $wallet->chartOfAccounts()->create([
            'parent_id' => $parent->id,
            'code' => $parent->code.'.'.($lastSegment + 1),
            'name' => $name,
            'type' => $type,
            'normal_balance' => ChartOfAccount::normalBalanceByType($type),
            'is_system' => false,
            'allows_posting' => true,
            'financial_group' => $financialGroup,
        ]);
    }
}
