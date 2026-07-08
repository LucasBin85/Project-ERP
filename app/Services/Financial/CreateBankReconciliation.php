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
            $linkedLineIds = collect($dto->statementItems)
                ->pluck('journal_line_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();

            $duplicatedLinkedIds = $linkedLineIds
                ->duplicates()
                ->values();

            if ($duplicatedLinkedIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'statement_items' => 'Um mesmo lançamento do sistema não pode ser vinculado a mais de um item do extrato.',
                ]);
            }

            $invalidIds = $linkedLineIds
                ->unique()
                ->reject(fn (int $id) => $availableLines->has($id));

            if ($invalidIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'statement_items' => 'Uma ou mais movimentações vinculadas não pertencem à conta, período ou carteira informados.',
                ]);
            }

            $statementMovementCents = collect($dto->statementItems)
                ->sum('amount_cents');

            $statementBalanceCents = (int) $preview['opening_balance_cents'] + (int) $statementMovementCents;

            $reconciledMovementCents = $linkedLineIds
                ->unique()
                ->map(fn (int $id) => $availableLines->get($id))
                ->sum('signed_amount_cents');

            $reconciledBalanceCents = (int) $preview['opening_balance_cents'] + (int) $reconciledMovementCents;
            $differenceCents = $reconciledBalanceCents - $statementBalanceCents;

            $hasPendingItems = collect($dto->statementItems)
                ->contains(fn (array $item) => empty($item['journal_line_id']));

            $status = $differenceCents === 0 && ! $hasPendingItems ? 'completed' : 'draft';

            $reconciliation = BankReconciliation::query()->create([
                'wallet_id' => $wallet->id,
                'bank_account_id' => $bankAccount->id,
                'period_start' => $dto->periodStart,
                'period_end' => $dto->periodEnd,
                'opening_balance_cents' => $preview['opening_balance_cents'],
                'statement_balance_cents' => $statementBalanceCents,
                'book_balance_cents' => $preview['book_balance_cents'],
                'reconciled_balance_cents' => $reconciledBalanceCents,
                'difference_cents' => $differenceCents,
                'status' => $status,
                'completed_at' => $status === 'completed' ? now() : null,
                'notes' => $dto->notes,
            ]);

            foreach ($dto->statementItems as $statementItem) {
                $linkedLineId = $statementItem['journal_line_id'] ?? null;
                $status = $linkedLineId ? 'reconciled' : 'pending';

                $reconciliation->statementItems()->create([
                    'journal_line_id' => $linkedLineId,
                    'transaction_date' => $statementItem['transaction_date'],
                    'description' => $statementItem['description'],
                    'amount_cents' => $statementItem['amount_cents'],
                    'status' => $status,
                ]);

                if ($linkedLineId) {
                    $line = $availableLines->get($linkedLineId);

                    $reconciliation->items()->create([
                        'journal_line_id' => $linkedLineId,
                        'amount_cents' => $line['signed_amount_cents'],
                    ]);
                }
            }

            return $reconciliation->fresh([
                'bankAccount',
                'statementItems.journalLine.journalEntry',
                'items.journalLine.journalEntry',
            ]);
        });
    }
}
