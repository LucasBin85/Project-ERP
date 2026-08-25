<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankReconciliationDraftDTO;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBankReconciliationDraft
{
    public function __construct(
        private readonly PrepareBankReconciliationSnapshot $prepareSnapshot,
        private readonly ReplaceBankReconciliationItems $replaceItems,
    ) {}

    public function execute(Wallet $wallet, BankReconciliation $reconciliation, BankReconciliationDraftDTO $dto): BankReconciliation
    {
        return DB::transaction(function () use ($wallet, $reconciliation, $dto) {
            $locked = BankReconciliation::query()
                ->where('wallet_id', $wallet->id)
                ->lockForUpdate()
                ->findOrFail($reconciliation->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Uma conciliação concluída é imutável.']);
            }

            $bankAccount = BankAccount::query()->where('wallet_id', $wallet->id)->findOrFail($locked->bank_account_id);
            $periodStart = $locked->period_start->toDateString();
            $periodEnd = $locked->period_end->toDateString();
            $snapshot = $this->prepareSnapshot->execute(
                $wallet,
                $bankAccount,
                $periodStart,
                $periodEnd,
                $dto->statementBalanceCents,
                $dto->statementItems,
                $locked->id,
            );
            $preview = $snapshot['preview'];

            if ($preview['has_existing_overlap']) {
                throw ValidationException::withMessages([
                    'status' => 'Outra conciliação já cobre parte deste período.',
                ]);
            }

            $this->replaceItems->execute($locked, $dto->statementItems, $snapshot['available_lines']);
            $locked->update([
                'opening_balance_cents' => $preview['opening_balance_cents'],
                'statement_balance_cents' => $dto->statementBalanceCents,
                'book_balance_cents' => $preview['book_balance_cents'],
                'reconciled_balance_cents' => $preview['reconciled_balance_cents'],
                'difference_cents' => $preview['difference_cents'],
                'status' => $preview['status'],
                'completed_at' => $preview['status'] === 'completed' ? now() : null,
                'notes' => $dto->notes,
            ]);

            return $locked->fresh(['bankAccount', 'statementItems.bankStatementImportTransaction.import', 'statementItems.journalLine.journalEntry', 'items.journalLine.journalEntry']);
        });
    }
}
