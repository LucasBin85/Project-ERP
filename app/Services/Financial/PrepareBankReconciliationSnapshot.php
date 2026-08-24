<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PrepareBankReconciliationSnapshot
{
    public function __construct(
        private readonly BankReconciliationPreviewService $previewService,
    ) {}

    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $periodStart,
        string $periodEnd,
        int $statementBalanceCents,
        array $statementItems,
        ?int $currentReconciliationId = null,
    ): array {
        Validator::make(compact('periodStart', 'periodEnd', 'statementItems'), [
            'periodStart' => ['required', 'date_format:Y-m-d'],
            'periodEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodStart'],
            'statementItems.*.transaction_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodStart', 'before_or_equal:periodEnd'],
        ])->validate();

        $preview = $this->previewService->buildForStatement(
            wallet: $wallet,
            bankAccount: $bankAccount,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            statementBalanceCents: $statementBalanceCents,
            statementItems: $statementItems,
            ignoredReconciliationId: $currentReconciliationId,
        );
        $availableLines = collect($preview['lines'])->keyBy('id');
        $items = collect($statementItems);

        $this->validateImportedTransactions($wallet, $bankAccount, $periodStart, $periodEnd, $items, $currentReconciliationId);

        $linkedLineIds = $items->pluck('journal_line_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $usedLineQuery = BankReconciliationItem::query()->whereIn('journal_line_id', $linkedLineIds);

        if ($currentReconciliationId !== null) {
            $usedLineQuery->where('bank_reconciliation_id', '!=', $currentReconciliationId);
        }

        if ($usedLineQuery->exists()) {
            throw ValidationException::withMessages([
                'statement_items' => 'Uma ou mais movimentações do sistema já pertencem a outra conciliação.',
            ]);
        }

        return ['preview' => $preview, 'available_lines' => $availableLines];
    }

    private function validateImportedTransactions(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $periodStart,
        string $periodEnd,
        Collection $statementItems,
        ?int $currentReconciliationId,
    ): void {
        $ids = $statementItems->pluck('bank_statement_import_transaction_id')->filter()->map(fn ($id) => (int) $id)->values();

        if ($ids->isEmpty()) {
            return;
        }

        if ($ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['statement_items' => 'Uma mesma transação importada não pode aparecer mais de uma vez na conciliação.']);
        }

        $transactions = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->whereDate('posted_at', '>=', $periodStart)
            ->whereDate('posted_at', '<=', $periodEnd)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($transactions->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['statement_items' => 'Uma ou mais transações importadas não pertencem à conta, período ou carteira informados.']);
        }

        $usedQuery = BankReconciliationStatementItem::query()->whereIn('bank_statement_import_transaction_id', $ids);

        if ($currentReconciliationId !== null) {
            $usedQuery->where('bank_reconciliation_id', '!=', $currentReconciliationId);
        }

        if ($usedQuery->exists()) {
            throw ValidationException::withMessages(['statement_items' => 'Uma ou mais transações importadas já foram conciliadas.']);
        }

        foreach ($statementItems as $item) {
            $id = $item['bank_statement_import_transaction_id'] ?? null;

            if (! $id) {
                continue;
            }

            $transaction = $transactions->get((int) $id);
            $signedAmount = $transaction->direction === 'in' ? (int) $transaction->amount_cents : -1 * (int) $transaction->amount_cents;

            if ($signedAmount !== (int) $item['amount_cents']) {
                throw ValidationException::withMessages(['statement_items' => 'O valor de uma transação importada foi alterado e não confere com o extrato.']);
            }
        }
    }
}
