<?php

namespace Tests\Helpers;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Wallet;

class FinancialTestHelper
{
    public static function bankChartAccount(
        Wallet $wallet,
        string $code,
        string $name,
        string $type = 'ativo',
        string $normalBalance = 'debit',
    ): ChartOfAccount {
        return ChartOfAccount::query()->updateOrCreate(
            [
                'wallet_id' => $wallet->id,
                'code' => $code,
            ],
            [
                'name' => $name,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'allows_posting' => true,
            ],
        );
    }

    public static function bankAccount(
        Wallet $wallet,
        string $code,
        string $name,
        array $attributes = [],
    ): BankAccount {
        $chartAccount = self::bankChartAccount(
            wallet: $wallet,
            code: $code,
            name: $name,
        );

        return BankAccount::query()->updateOrCreate(
            [
                'wallet_id' => $wallet->id,
                'chart_of_account_id' => $chartAccount->id,
            ],
            array_merge([
                'name' => $name,
                'bank_name' => $name,
                'account_type' => 'checking',
                'opening_balance_cents' => 0,
                'is_active' => true,
            ], $attributes),
        );
    }
}
