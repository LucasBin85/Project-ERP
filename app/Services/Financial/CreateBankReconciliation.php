<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateBankReconciliation
{
    public function __construct(
        private readonly BankReconciliationPreviewService $previewService,
    ) {}

    public function execute(Wallet $wallet, BankReconciliationDTO $dto): BankReconciliation
    {
        Validator::make([
            'period_start' => $dto->periodStart,
            'period_end' => $dto->periodEnd,
            'statement_items' => $dto->statementItems,
        ], [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'statement_items.*.transaction_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:period_start',
                'before_or_equal:period_end',
            ],
        ])->validate();

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

            $preview = $this->previewService->buildForStatement(
                wallet: $wallet,
                bankAccount: $bankAccount,
                periodStart: $dto->periodStart,
                periodEnd: $dto->periodEnd,
                statementBalanceCents: $dto->statementBalanceCents,
                statementItems: $dto->statementItems,
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

            $alreadyReconciledLines = BankReconciliationItem::query()
                ->whereIn('journal_line_id', $linkedLineIds->unique())
                ->exists();

            if ($alreadyReconciledLines) {
                throw ValidationException::withMessages([
                    'statement_items' => 'Uma ou mais movimentações do sistema já pertencem a outra conciliação.',
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
                'status' => $preview['status'],
                'completed_at' => $preview['status'] === 'completed' ? now() : null,
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
                'statement_items' => 'Uma mesma transação importada não pode aparecer mais de uma vez na conciliação.',
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
                    'statement_items' => 'O valor de uma transação importada foi alterado e não confere com o extrato.',
                ]);
            }
        }
    }
}
