<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class CreateBankAccount
{
    public function execute(Wallet $wallet, array $data): BankAccount
    {
        return DB::transaction(function () use ($wallet, $data) {
            $parent = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('code', '1.1.2')
                ->where('type', 'ativo')
                ->firstOrFail();

            $chartAccount = ChartOfAccount::query()->create([
                'wallet_id' => $wallet->id,
                'parent_id' => $parent->id,
                'code' => $this->nextChildCode($wallet, $parent),
                'name' => $data['name'],
                'type' => 'ativo',
                'normal_balance' => 'debit',
                'allows_posting' => true,
                'is_system' => false,
            ]);

            $bankAccount = BankAccount::query()->create([
                'wallet_id' => $wallet->id,
                'chart_of_account_id' => $chartAccount->id,
                'name' => $data['name'],
                'bank_name' => $data['bank_name'] ?? $data['name'],
                'bank_code' => $data['bank_code'] ?? null,
                'agency' => $data['agency'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'account_type' => $data['account_type'],
                'opening_balance_cents' => (int) ($data['opening_balance_cents'] ?? 0),
                'is_active' => true,
            ]);

            $openingBalance = (int) ($data['opening_balance_cents'] ?? 0);

            if ($openingBalance > 0) {
                $this->createOpeningBalanceEntry(
                    wallet: $wallet,
                    bankAccount: $bankAccount,
                    bankChartAccount: $chartAccount,
                    amountCents: $openingBalance,
                    entryDate: $data['opening_balance_date'],
                );
            }

            return $bankAccount;
        });
    }

    private function createOpeningBalanceEntry(
        Wallet $wallet,
        BankAccount $bankAccount,
        ChartOfAccount $bankChartAccount,
        int $amountCents,
        string $entryDate,
    ): void {
        $openingEquityAccount = $this->resolveOpeningBalanceAccount($wallet);

        $entry = JournalEntry::query()->create([
            'wallet_id' => $wallet->id,
            'source' => 'manual',
            'entry_date' => $entryDate,
            'description' => "Saldo inicial - {$bankAccount->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'is_balanced' => true,
            'debit_total' => $amountCents,
            'credit_total' => $amountCents,
            'diff_total' => 0,
        ]);

        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $bankChartAccount->id,
            'type' => 'debit',
            'amount_cents' => $amountCents,
        ]);

        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $openingEquityAccount->id,
            'type' => 'credit',
            'amount_cents' => $amountCents,
        ]);
    }

    private function resolveOpeningBalanceAccount(Wallet $wallet): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '3.9')
            ->where('type', 'patrimonio')
            ->firstOrFail();
    }

    private function nextChildCode(Wallet $wallet, ChartOfAccount $parent): string
    {
        $lastCode = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('parent_id', $parent->id)
            ->where('code', 'like', $parent->code . '.%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderByDesc('code')
            ->value('code');

        if (! $lastCode) {
            return $parent->code . '.001';
        }

        $lastSegment = (int) str($lastCode)->afterLast('.')->toString();

        return $parent->code . '.' . str_pad((string) ($lastSegment + 1), 3, '0', STR_PAD_LEFT);
    }
}