<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBankReconciliation
{
    public function __construct(
        private readonly PrepareBankReconciliationSnapshot $prepareSnapshot,
        private readonly ReplaceBankReconciliationItems $replaceItems,
    ) {}

    public function execute(Wallet $wallet, BankReconciliationDTO $dto): BankReconciliation
    {
        return DB::transaction(function () use ($wallet, $dto) {
            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($dto->bankAccountId);

            $hasOverlap = BankReconciliation::query()
                ->where('wallet_id', $wallet->id)
                ->where('bank_account_id', $bankAccount->id)
                ->whereDate('period_start', '<=', $dto->periodEnd)
                ->whereDate('period_end', '>=', $dto->periodStart)
                ->exists();

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'period_start' => 'Já existe uma conciliação para esta conta em um período sobreposto.',
                ]);
            }

            $snapshot = $this->prepareSnapshot->execute(
                $wallet,
                $bankAccount,
                $dto->periodStart,
                $dto->periodEnd,
                $dto->statementBalanceCents,
                $dto->statementItems,
            );
            $preview = $snapshot['preview'];

            $reconciliation = BankReconciliation::query()->create([
                'wallet_id' => $wallet->id,
                'bank_account_id' => $bankAccount->id,
                'period_start' => $dto->periodStart,
                'period_end' => $dto->periodEnd,
                'opening_balance_cents' => $preview['opening_balance_cents'],
                'statement_balance_cents' => $dto->statementBalanceCents,
                'book_balance_cents' => $preview['book_balance_cents'],
                'reconciled_balance_cents' => $preview['reconciled_balance_cents'],
                'difference_cents' => $preview['difference_cents'],
                'status' => 'draft',
                'completed_at' => null,
                'notes' => $dto->notes,
            ]);

            $this->replaceItems->execute($reconciliation, $dto->statementItems, $snapshot['available_lines']);

            $reconciliation->update([
                'status' => $preview['status'],
                'completed_at' => $preview['status'] === 'completed' ? now() : null,
            ]);

            return $reconciliation->fresh([
                'bankAccount',
                'statementItems.bankStatementImportTransaction.import',
                'statementItems.journalLine.journalEntry',
                'items.journalLine.journalEntry',
            ]);
        });
    }
}
