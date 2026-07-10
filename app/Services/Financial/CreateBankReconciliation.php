<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
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
            $statementItems = collect($dto->statementItems);

            $this->validateOfxTransactions(
                wallet: $wallet,
                bankAccount: $bankAccount,
                periodStart: $dto->periodStart,
                periodEnd: $dto->periodEnd,
                statementItems: $statementItems,
            );

            $linkedLineIds = $statementItems
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

            $statementMovementCents = $statementItems
                ->sum('amount_cents');

            $statementBalanceCents = (int) $preview['opening_balance_cents'] + (int) $statementMovementCents;

            $reconciledMovementCents = $linkedLineIds
                ->unique()
                ->map(fn (int $id) => $availableLines->get($id))
                ->sum('signed_amount_cents');

            $reconciledBalanceCents = (int) $preview['opening_balance_cents'] + (int) $reconciledMovementCents;
            $differenceCents = $reconciledBalanceCents - $statementBalanceCents;

            $hasPendingItems = $statementItems
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
                    'bank_statement_import_transaction_id' => $statementItem['bank_statement_import_transaction_id'] ?? null,
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
                'statementItems.bankStatementImportTransaction.import',
                'statementItems.journalLine.journalEntry',
                'items.journalLine.journalEntry',
            ]);
        });
    }

    private function validateOfxTransactions(Wallet $wallet, BankAccount $bankAccount, string $periodStart, string $periodEnd, $statementItems): void
    {
        $ofxIds = $statementItems
            ->pluck('bank_statement_import_transaction_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($ofxIds->isEmpty()) {
            return;
        }

        if ($ofxIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'statement_items' => 'Uma mesma transação OFX não pode aparecer mais de uma vez na conciliação.',
            ]);
        }

        $transactions = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->whereDate('posted_at', '>=', $periodStart)
            ->whereDate('posted_at', '<=', $periodEnd)
            ->whereIn('id', $ofxIds)
            ->get()
            ->keyBy('id');

        if ($transactions->count() !== $ofxIds->unique()->count()) {
            throw ValidationException::withMessages([
                'statement_items' => 'Uma ou mais transações OFX não pertencem à conta, período ou carteira informados.',
            ]);
        }

        $alreadyReconciled = BankReconciliationStatementItem::query()
            ->whereIn('bank_statement_import_transaction_id', $ofxIds)
            ->exists();

        if ($alreadyReconciled) {
            throw ValidationException::withMessages([
                'statement_items' => 'Uma ou mais transações OFX já foram conciliadas.',
            ]);
        }

        foreach ($statementItems as $item) {
            $ofxId = $item['bank_statement_import_transaction_id'] ?? null;

            if (! $ofxId) {
                continue;
            }

            $transaction = $transactions->get((int) $ofxId);
            $signedAmount = $transaction->direction === 'in'
                ? (int) $transaction->amount_cents
                : -1 * (int) $transaction->amount_cents;

            if ($signedAmount !== (int) $item['amount_cents']) {
                throw ValidationException::withMessages([
                    'statement_items' => 'O valor de uma transação OFX foi alterado e não confere com o extrato importado.',
                ]);
            }
        }
    }
}
