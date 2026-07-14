<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankAccountDTO;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateBankAccount
{
    public function execute(Wallet $wallet, BankAccountDTO $dto): BankAccount
    {
        return DB::transaction(function () use ($wallet, $dto) {
            if ($dto->openingBalanceCents > 0 && $dto->openingBalanceDate === null) {
                throw new InvalidArgumentException('A data do saldo inicial é obrigatória quando há saldo inicial.');
            }

            $bank = Bank::query()
                ->whereKey($dto->bankId)
                ->where('active', true)
                ->firstOrFail();

            $this->assertNoDuplicateBankAccount($wallet, $bank, $dto);

            $parent = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('code', '1.1.2')
                ->where('type', 'ativo')
                ->firstOrFail();

            $chartAccount = ChartOfAccount::query()->create([
                'wallet_id' => $wallet->id,
                'parent_id' => $parent->id,
                'code' => $this->nextChildCode($wallet, $parent),
                'name' => $dto->name,
                'type' => 'ativo',
                'normal_balance' => 'debit',
                'allows_posting' => true,
                'is_system' => false,
                'financial_group' => 'available',
            ]);

            $bankAccount = BankAccount::query()->create([
                'wallet_id' => $wallet->id,
                'chart_of_account_id' => $chartAccount->id,
                'bank_id' => $bank->id,
                'name' => $dto->name,
                'bank_name' => $bank->short_name,
                'bank_code' => $bank->code,
                'agency' => $dto->agency,
                'account_number' => $dto->accountNumber,
                'account_type' => $dto->accountType,
                'opening_balance_cents' => $dto->openingBalanceCents,
                'is_active' => true,
            ]);

            $openingBalance = $dto->openingBalanceCents;

            if ($openingBalance > 0) {
                $this->createOpeningBalanceEntry(
                    wallet: $wallet,
                    bankAccount: $bankAccount,
                    bankChartAccount: $chartAccount,
                    amountCents: $openingBalance,
                    entryDate: (string) $dto->openingBalanceDate,
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
            'balance_diff_cents' => 0,
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

    private function assertNoDuplicateBankAccount(
        Wallet $wallet,
        Bank $bank,
        BankAccountDTO $dto,
    ): void {
        if ($dto->accountNumber === null) {
            return;
        }

        $duplicateExists = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where(function ($query) use ($bank) {
                $query->where('bank_id', $bank->id)
                    ->orWhereNull('bank_id');
            })
            ->get(['bank_id', 'bank_code', 'agency', 'account_number'])
            ->contains(fn (BankAccount $account) => ((int) $account->bank_id === (int) $bank->id
                || ($account->bank_id === null
                    && $this->normalizeAccountIdentifier($account->bank_code)
                        === $this->normalizeAccountIdentifier($bank->code)))
                && $this->normalizeAccountIdentifier($account->agency)
                    === $this->normalizeAccountIdentifier($dto->agency)
                && $this->normalizeAccountIdentifier($account->account_number)
                    === $this->normalizeAccountIdentifier($dto->accountNumber));

        if ($duplicateExists) {
            throw new InvalidArgumentException(
                'Já existe uma conta deste banco com a mesma agência e número na wallet ativa.',
            );
        }
    }

    private function normalizeAccountIdentifier(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));

        return ltrim($normalized, '0') ?: '0';
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
            ->where('code', 'like', $parent->code.'.%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderByDesc('code')
            ->value('code');

        if (! $lastCode) {
            return $parent->code.'.001';
        }

        $lastSegment = (int) str($lastCode)->afterLast('.')->toString();

        return $parent->code.'.'.str_pad((string) ($lastSegment + 1), 3, '0', STR_PAD_LEFT);
    }
}
