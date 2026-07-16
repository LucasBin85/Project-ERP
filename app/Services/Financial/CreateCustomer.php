<?php

namespace App\Services\Financial;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateCustomer
{
    public function execute(Wallet $wallet, array $data): Customer
    {
        return DB::transaction(function () use ($wallet, $data) {
            $receivableAccountId = $data['receivable_account_id'] ?? null;
            $revenueAccountId = $data['default_revenue_account_id'] ?? null;

            if (! $receivableAccountId) {
                $receivableAccountId = $this->createPostingAccount($wallet, '1.2', $data['name'], 'ativo', 'accounts_receivable')->id;
            }

            if (! $revenueAccountId) {
                $revenueAccountId = $this->createPostingAccount($wallet, '4.1', $data['default_revenue_name'] ?: $data['name'], 'receita')->id;
            }

            return Customer::query()->create([
                'wallet_id' => $wallet->id,
                'name' => $data['name'],
                'document' => $data['document'] ?? null,
                'receivable_account_id' => $receivableAccountId,
                'default_revenue_account_id' => $revenueAccountId,
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
