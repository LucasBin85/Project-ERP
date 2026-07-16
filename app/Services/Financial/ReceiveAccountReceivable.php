<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveAccountReceivable
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
    ) {}

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

            $accountReceivable->load('receivableAccount');

            if (! $accountReceivable->receivableAccount?->allows_posting) {
                throw ValidationException::withMessages([
                    'receivable_account_id' => 'Conta de controle do cliente inválida para recebimento.',
                ]);
            }

            $journalEntry = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id,
                'entry_date' => $dto->receivedAt,
                'description' => 'Recebimento: '.$accountReceivable->description,
                'lines' => [
                    [
                        'chart_of_account_id' => $bankAccount->chart_of_account_id,
                        'type' => 'debit',
                        'amount_cents' => $accountReceivable->amount_cents,
                    ],
                    [
                        'chart_of_account_id' => $accountReceivable->receivable_account_id,
                        'type' => 'credit',
                        'amount_cents' => $accountReceivable->amount_cents,
                    ],
                ],
            ]);

            $accountReceivable->update([
                'bank_account_id' => $bankAccount->id,
                'receipt_journal_entry_id' => $journalEntry->id,
                'received_at' => $dto->receivedAt,
                'status' => 'received',
            ]);

            return $accountReceivable->fresh([
                'revenueAccount',
                'receivableAccount',
                'bankAccount',
                'receiptJournalEntry.lines.chartOfAccount',
            ]);
        });
    }
}
