<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveAccountReceivable
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly PostJournalEntry $postJournalEntry,
    ) {
    }

    public function execute(Wallet $wallet, AccountReceivable $accountReceivable, ReceiveAccountReceivableDTO $dto): AccountReceivable
    {
        return DB::transaction(function () use ($wallet, $accountReceivable, $dto) {
            if ($accountReceivable->wallet_id !== $wallet->id) {
                abort(404);
            }

            if ($accountReceivable->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Apenas contas pendentes podem ser recebidas.',
                ]);
            }

            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->with('chartOfAccount')
                ->find($dto->bankAccountId);

            if (! $bankAccount || ! $bankAccount->chartOfAccount?->allows_posting) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Conta bancária inválida para recebimento.',
                ]);
            }

            $accountReceivable->load('revenueAccount');

            if (! $accountReceivable->revenueAccount?->allows_posting) {
                throw ValidationException::withMessages([
                    'revenue_account_id' => 'Conta de receita inválida para recebimento.',
                ]);
            }

            $journalEntry = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id,
                'entry_date' => $dto->receivedAt,
                'description' => 'Recebimento: ' . $accountReceivable->description,
                'lines' => [
                    [
                        'chart_of_account_id' => $bankAccount->chart_of_account_id,
                        'type' => 'debit',
                        'amount_cents' => $accountReceivable->amount_cents,
                    ],
                    [
                        'chart_of_account_id' => $accountReceivable->revenue_account_id,
                        'type' => 'credit',
                        'amount_cents' => $accountReceivable->amount_cents,
                    ],
                ],
            ]);

            $journalEntry = $this->postJournalEntry->handle($journalEntry);

            $accountReceivable->update([
                'bank_account_id' => $bankAccount->id,
                'receipt_journal_entry_id' => $journalEntry->id,
                'received_at' => $dto->receivedAt,
                'status' => 'received',
            ]);

            return $accountReceivable->fresh([
                'revenueAccount',
                'bankAccount',
                'receiptJournalEntry.lines.chartOfAccount',
            ]);
        });
    }
}
