<?php

namespace App\Services\Financial;

use App\Models\ChartOfAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateInvestmentAccount
{
    public function execute(Wallet $wallet, string $name): ChartOfAccount
    {
        $name = Str::squish($name);

        return DB::transaction(function () use ($wallet, $name) {
            $group = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('code', '1.3')
                ->where('type', 'ativo')
                ->where('financial_group', 'investments')
                ->lockForUpdate()
                ->first();

            if (! $group) {
                throw new InvalidArgumentException('O grupo 1.3 Investimentos não está disponível na wallet ativa.');
            }

            $duplicate = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();
            if ($duplicate) {
                throw new InvalidArgumentException('Já existe uma conta contábil com este nome na wallet ativa.');
            }

            $lastSegment = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('parent_id', $group->id)
                ->pluck('code')
                ->map(fn (string $code) => (int) Str::afterLast($code, '.'))
                ->max() ?? 0;

            return ChartOfAccount::query()->create([
                'wallet_id' => $wallet->id,
                'parent_id' => $group->id,
                'code' => $group->code.'.'.($lastSegment + 1),
                'name' => $name,
                'type' => 'ativo',
                'normal_balance' => 'debit',
                'is_system' => false,
                'allows_posting' => true,
                'financial_group' => 'investments',
            ]);
        });
    }
}
