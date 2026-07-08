<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankTransferDTO;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBankTransfer
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly PostJournalEntry $postJournalEntry,
    ) {
    }

    public function execute(Wallet $wallet, BankTransferDTO $dto): BankTransfer
    {
        return DB::transaction(function () use ($wallet, $dto) {
            if ($dto->fromBankAccountId === $dto->toBankAccountId) {
                throw ValidationException::withMessages([
                    'to_bank_account_id' => 'A conta de destino deve ser diferente da conta de origem.',
                ]);
            }

            $fromBankAccount = $this->resolveBankAccount($wallet, $dto->fromBankAccountId, 'from_bank_account_id');
            $toBankAccount = $this->resolveBankAccount($wallet, $dto->toBankAccountId, 'to_bank_account_id');

            $journalEntry = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id,
                'entry_date' => $dto->transferDate,
                'description' => $dto->description,
                'lines' => [
                    [
                        'chart_of_account_id' => $toBankAccount->chart_of_account_id,
                        'type' => 'debit',
                        'amount_cents' => $dto->amountCents,
                    ],
                    [
                        'chart_of_account_id' => $fromBankAccount->chart_of_account_id,
                        'type' => 'credit',
                        'amount_cents' => $dto->amountCents,
                    ],
                ],
            ]);

            $journalEntry = $this->postJournalEntry->handle($journalEntry);

            $bankTransfer = BankTransfer::query()->create([
                'wallet_id' => $wallet->id,
                'from_bank_account_id' => $fromBankAccount->id,
                'to_bank_account_id' => $toBankAccount->id,
                'journal_entry_id' => $journalEntry->id,
                'transfer_date' => $dto->transferDate,
                'amount_cents' => $dto->amountCents,
                'description' => $dto->description,
                'status' => 'posted',
            ]);

            return $bankTransfer->fresh([
                'fromBankAccount.chartOfAccount',
                'toBankAccount.chartOfAccount',
                'journalEntry.lines.chartOfAccount',
            ]);
        });
    }

    private function resolveBankAccount(Wallet $wallet, int $bankAccountId, string $field): BankAccount
    {
        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->with('chartOfAccount')
            ->find($bankAccountId);

        if (! $bankAccount || ! $bankAccount->chartOfAccount?->allows_posting) {
            throw ValidationException::withMessages([
                $field => 'Conta bancária inválida para transferência.',
            ]);
        }

        return $bankAccount;
    }
}
