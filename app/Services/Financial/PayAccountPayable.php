<?php

namespace App\Services\Financial;

use App\DTOs\Financial\PayAccountPayableDTO;
use App\Models\AccountPayable;
use App\Models\BankAccount;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayAccountPayable
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly PostJournalEntry $postJournalEntry,
    ) {
    }

    public function execute(Wallet $wallet, AccountPayable $accountPayable, PayAccountPayableDTO $dto): AccountPayable
    {
        return DB::transaction(function () use ($wallet, $accountPayable, $dto) {
            if ($accountPayable->wallet_id !== $wallet->id) {
                abort(404);
            }

            if ($accountPayable->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Apenas contas pendentes podem ser pagas.',
                ]);
            }

            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->with('chartOfAccount')
                ->find($dto->bankAccountId);

            if (! $bankAccount || ! $bankAccount->chartOfAccount?->allows_posting) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Conta bancária inválida para pagamento.',
                ]);
            }

            $accountPayable->load('expenseAccount');

            if (! $accountPayable->expenseAccount?->allows_posting) {
                throw ValidationException::withMessages([
                    'expense_account_id' => 'Conta de despesa inválida para pagamento.',
                ]);
            }

            $journalEntry = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id,
                'entry_date' => $dto->paidAt,
                'description' => 'Pagamento: ' . $accountPayable->description,
                'lines' => [
                    [
                        'chart_of_account_id' => $accountPayable->expense_account_id,
                        'type' => 'debit',
                        'amount_cents' => $accountPayable->amount_cents,
                    ],
                    [
                        'chart_of_account_id' => $bankAccount->chart_of_account_id,
                        'type' => 'credit',
                        'amount_cents' => $accountPayable->amount_cents,
                    ],
                ],
            ]);

            $journalEntry = $this->postJournalEntry->handle($journalEntry);

            $accountPayable->update([
                'bank_account_id' => $bankAccount->id,
                'payment_journal_entry_id' => $journalEntry->id,
                'paid_at' => $dto->paidAt,
                'status' => 'paid',
            ]);

            return $accountPayable->fresh([
                'expenseAccount',
                'bankAccount',
                'paymentJournalEntry.lines.chartOfAccount',
            ]);
        });
    }
}
