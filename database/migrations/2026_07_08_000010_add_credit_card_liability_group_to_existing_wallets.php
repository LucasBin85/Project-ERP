<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $wallets = DB::table('wallets')->get(['id']);

        foreach ($wallets as $wallet) {
            $parent = DB::table('chart_of_accounts')
                ->where('wallet_id', $wallet->id)
                ->where('code', '2')
                ->first(['id']);

            if (! $parent) {
                continue;
            }

            $exists = DB::table('chart_of_accounts')
                ->where('wallet_id', $wallet->id)
                ->where('code', '2.2')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('chart_of_accounts')->insert([
                'wallet_id' => $wallet->id,
                'parent_id' => $parent->id,
                'code' => '2.2',
                'name' => 'Cartões de Crédito',
                'type' => 'passivo',
                'normal_balance' => 'credit',
                'is_system' => true,
                'allows_posting' => false,
                'financial_group' => 'accounts_payable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Mantém o grupo contábil para evitar quebrar carteiras que já tenham
        // contas filhas de cartão vinculadas a lançamentos ou cartões.
    }
};
