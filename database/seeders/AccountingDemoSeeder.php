<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class AccountingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'demo@erp.test'],
            [
                'name' => 'Demo ERP',
                'password' => bcrypt('password'),
            ],
        );

        $wallet = Wallet::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'name' => 'Carteira Demo',
            ],
        );

        $accounts = collect([
            ['code' => '1', 'name' => 'Ativo', 'type' => 'ativo', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '1.1', 'name' => 'Disponível', 'type' => 'ativo', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '1.1.1', 'name' => 'Banco', 'type' => 'ativo', 'normal_balance' => 'debit', 'allows_posting' => true],

            ['code' => '2', 'name' => 'Passivo', 'type' => 'passivo', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '2.1', 'name' => 'Fornecedores', 'type' => 'passivo', 'normal_balance' => 'credit', 'allows_posting' => true],

            ['code' => '3', 'name' => 'Patrimônio Líquido', 'type' => 'patrimonio', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '3.1', 'name' => 'Capital Social', 'type' => 'patrimonio', 'normal_balance' => 'credit', 'allows_posting' => true],

            ['code' => '4', 'name' => 'Receitas', 'type' => 'receita', 'normal_balance' => 'credit', 'allows_posting' => false],
            ['code' => '4.1', 'name' => 'Receita de Serviços', 'type' => 'receita', 'normal_balance' => 'credit', 'allows_posting' => true],

            ['code' => '5', 'name' => 'Despesas', 'type' => 'despesa', 'normal_balance' => 'debit', 'allows_posting' => false],
            ['code' => '5.1', 'name' => 'Despesas Administrativas', 'type' => 'despesa', 'normal_balance' => 'debit', 'allows_posting' => true],
        ]);

        $created = [];

        foreach ($accounts as $account) {
            $parentCode = str_contains($account['code'], '.')
                ? substr($account['code'], 0, strrpos($account['code'], '.'))
                : null;

            $created[$account['code']] = ChartOfAccount::query()->updateOrCreate(
                [
                    'wallet_id' => $wallet->id,
                    'code' => $account['code'],
                ],
                [
                    'parent_id' => $parentCode ? ($created[$parentCode]->id ?? null) : null,
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'normal_balance' => $account['normal_balance'],
                    'allows_posting' => $account['allows_posting'],
                ],
            );
        }

        $this->entry($wallet, '2026-01-01', 'Integralização de capital', [
            ['account' => $created['1.1.1'], 'type' => 'debit', 'amount' => 1000000],
            ['account' => $created['3.1'], 'type' => 'credit', 'amount' => 1000000],
        ]);

        $this->entry($wallet, '2026-06-01', 'Receita de serviços', [
            ['account' => $created['1.1.1'], 'type' => 'debit', 'amount' => 850000],
            ['account' => $created['4.1'], 'type' => 'credit', 'amount' => 850000],
        ]);

        $this->entry($wallet, '2026-06-10', 'Despesa administrativa', [
            ['account' => $created['5.1'], 'type' => 'debit', 'amount' => 35590],
            ['account' => $created['1.1.1'], 'type' => 'credit', 'amount' => 35590],
        ]);
    }

    private function entry(Wallet $wallet, string $date, string $description, array $lines): void
    {
        if (JournalEntry::query()->where('wallet_id', $wallet->id)->where('description', $description)->whereDate('entry_date', $date)->exists()) {
            return;
        }

        $debit = collect($lines)->where('type', 'debit')->sum('amount');
        $credit = collect($lines)->where('type', 'credit')->sum('amount');

        $entry = JournalEntry::query()->create([
            'wallet_id' => $wallet->id,
            'source' => 'manual',
            'entry_date' => $date,
            'description' => $description,
            'status' => 'posted',
            'posted_at' => now(),
            'is_balanced' => $debit === $credit,
            'balance_diff_cents' => $debit - $credit,
        ]);

        foreach ($lines as $line) {
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $line['account']->id,
                'type' => $line['type'],
                'amount_cents' => $line['amount'],
            ]);
        }
    }
}
