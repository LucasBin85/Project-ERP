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
        private readonly BankReconciliationPreviewService $previewService,
    ) {
    }

    public function execute(Wallet $wallet, BankReconciliationDTO $dto): BankReconciliation
    {
        return DB::transaction(function () use ($wallet, $dto) {
            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->findOrFail($dto->bankAccountId);

            $preview = $this->previewService->build(
                wallet: $wallet,
                bankAccount: $bankAccount,
                periodStart: $dto->periodStart,
                periodEnd: $dto->periodEnd,
            );

            $availableLines = collect($preview['lines'])->keyBy('id');
            $selectedIds = collect($dto->journalLineIds)->unique()->values();

            $invalidIds = $selectedIds->reject(fn (int $id) => $availableLines->has($id));

            if ($invalidIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'journal_line_ids' => 'Uma ou mais movimentações selecionadas não pertencem à conta, período ou carteira informados.',
                ]);
            }

            $selectedLines = $selectedIds->map(fn (int $id) => $availableLines->get($id));
            $reconciledMovementCents = (int) $selectedLines->sum('signed_amount_cents');
            $reconciledBalanceCents = (int) $preview['opening_balance_cents'] + $reconciledMovementCents;
            $differenceCents = $reconciledBalanceCents - $dto->statementBalanceCents;
            $status = $differenceCents === 0 ? 'completed' : 'draft';

            $reconciliation = BankReconciliation::query()->create([
                'wallet_id' => $wallet->id,
                'bank_account_id' => $bankAccount->id,
                'period_start' => $dto->periodStart,
                'period_end' => $dto->periodEnd,
                'opening_balance_cents' => $preview['opening_balance_cents'],
                'statement_balance_cents' => $dto->statementBalanceCents,
                'book_balance_cents' => $preview['book_balance_cents'],
                'reconciled_balance_cents' => $reconciledBalanceCents,
                'difference_cents' => $differenceCents,
                'status' => $status,
                'completed_at' => $status === 'completed' ? now() : null,
                'notes' => $dto->notes,
            ]);

            foreach ($selectedLines as $line) {
                $reconciliation->items()->create([
                    'journal_line_id' => $line['id'],
                    'amount_cents' => $line['signed_amount_cents'],
                ]);
            }

            return $reconciliation->fresh([
                'bankAccount',
                'items.journalLine.journalEntry',
            ]);
        });
    }
}
