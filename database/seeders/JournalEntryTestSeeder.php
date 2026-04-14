<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Database\Seeder;

class JournalEntryTestSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();

        if (! $user) {
            $this->command?->warn('Nenhum usuário encontrado. Crie um usuário antes de rodar o seeder.');
            return;
        }

        // Usa a wallet já criada automaticamente para o usuário
        $wallet = $user->wallets()->first();

        if (! $wallet) {
            $this->command?->warn('Usuário sem wallet vinculada.');
            return;
        }

        // Garante que existe conta suspense
        $suspense = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '1.1.99')
            ->first();

        if (! $suspense) {
            $this->command?->warn('Conta "A classificar" não encontrada. Verifique a criação do plano base.');
            return;
        }

        if (! $wallet->suspense_account_id) {
            $wallet->update([
                'suspense_account_id' => $suspense->id,
            ]);
        }

        // Conta bancária lançável
        $bank = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '1.1.2.001')
            ->where('allows_posting', true)
            ->first();

        if (! $bank) {
            $this->command?->warn('Conta bancária lançável 1.1.2.001 não encontrada.');
            return;
        }

        // Conta de despesa lançável
        $expense = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '5.1.1')
            ->where('allows_posting', true)
            ->first();

        if (! $expense) {
            $this->command?->warn('Conta de despesa lançável 5.1.1 não encontrada.');
            return;
        }

        // Conta de receita lançável
        $income = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '4.1.1')
            ->where('allows_posting', true)
            ->first();

        if (! $income) {
            $this->command?->warn('Conta de receita lançável 4.1.1 não encontrada.');
            return;
        }

        if (JournalEntry::query()->where('wallet_id', $wallet->id)->exists()) {
            $this->command?->info('Já existem lançamentos nessa wallet. Seeder não inseriu duplicados.');
            return;
        }

        $entry1 = JournalEntry::create([
            'wallet_id' => $wallet->id,
            'source' => 'ofx',
            'external_id' => 'demo-001',
            'entry_date' => now()->subDays(5)->toDateString(),
            'description' => 'Compra no mercado',
            'status' => 'draft',
            'is_balanced' => true,
            'balance_diff_cents' => 0,
        ]);

        JournalLine::insert([
            [
                'journal_entry_id' => $entry1->id,
                'chart_of_account_id' => $bank->id,
                'type' => 'credit',
                'amount_cents' => 12590,
                'memo' => 'Saída da conta bancária',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_entry_id' => $entry1->id,
                'chart_of_account_id' => $wallet->suspense_account_id,
                'type' => 'debit',
                'amount_cents' => 12590,
                'memo' => 'A classificar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $entry2 = JournalEntry::create([
            'wallet_id' => $wallet->id,
            'source' => 'open_finance',
            'external_id' => 'demo-002',
            'entry_date' => now()->subDays(4)->toDateString(),
            'description' => 'Recebimento PIX',
            'status' => 'draft',
            'is_balanced' => true,
            'balance_diff_cents' => 0,
        ]);

        JournalLine::insert([
            [
                'journal_entry_id' => $entry2->id,
                'chart_of_account_id' => $bank->id,
                'type' => 'debit',
                'amount_cents' => 350000,
                'memo' => 'Entrada na conta bancária',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_entry_id' => $entry2->id,
                'chart_of_account_id' => $wallet->suspense_account_id,
                'type' => 'credit',
                'amount_cents' => 350000,
                'memo' => 'A classificar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $entry3 = JournalEntry::create([
            'wallet_id' => $wallet->id,
            'source' => 'manual',
            'external_id' => 'demo-003',
            'entry_date' => now()->subDays(3)->toDateString(),
            'description' => 'Combustível',
            'status' => 'posted',
            'posted_at' => now()->subDays(3),
            'is_balanced' => true,
            'balance_diff_cents' => 0,
        ]);

        JournalLine::insert([
            [
                'journal_entry_id' => $entry3->id,
                'chart_of_account_id' => $expense->id,
                'type' => 'debit',
                'amount_cents' => 23000,
                'memo' => 'Despesa operacional',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_entry_id' => $entry3->id,
                'chart_of_account_id' => $bank->id,
                'type' => 'credit',
                'amount_cents' => 23000,
                'memo' => 'Pagamento via banco',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $entry4 = JournalEntry::create([
            'wallet_id' => $wallet->id,
            'source' => 'manual',
            'external_id' => 'demo-004',
            'entry_date' => now()->subDays(2)->toDateString(),
            'description' => 'Venda de serviço',
            'status' => 'posted',
            'posted_at' => now()->subDays(2),
            'is_balanced' => true,
            'balance_diff_cents' => 0,
        ]);

        JournalLine::insert([
            [
                'journal_entry_id' => $entry4->id,
                'chart_of_account_id' => $bank->id,
                'type' => 'debit',
                'amount_cents' => 850000,
                'memo' => 'Recebimento bancário',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_entry_id' => $entry4->id,
                'chart_of_account_id' => $income->id,
                'type' => 'credit',
                'amount_cents' => 850000,
                'memo' => 'Receita operacional',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $entry5 = JournalEntry::create([
            'wallet_id' => $wallet->id,
            'source' => 'ofx',
            'external_id' => 'demo-005',
            'entry_date' => now()->subDay()->toDateString(),
            'description' => 'Padaria',
            'status' => 'draft',
            'is_balanced' => true,
            'balance_diff_cents' => 0,
        ]);

        JournalLine::insert([
            [
                'journal_entry_id' => $entry5->id,
                'chart_of_account_id' => $bank->id,
                'type' => 'credit',
                'amount_cents' => 1890,
                'memo' => 'Saída da conta bancária',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_entry_id' => $entry5->id,
                'chart_of_account_id' => $wallet->suspense_account_id,
                'type' => 'debit',
                'amount_cents' => 1890,
                'memo' => 'A classificar',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->command?->info('JournalEntryTestSeeder executado com sucesso.');
    }
}