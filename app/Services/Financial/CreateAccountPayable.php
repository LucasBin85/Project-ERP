<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountPayableDTO;
use App\Models\AccountPayable;
use App\Models\ChartOfAccount;
use App\Models\FinancialTitleSeries;
use App\Models\Supplier;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAccountPayable
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly InstallmentSchedule $installmentSchedule,
        private readonly EnsureAccountingPeriodIsOpen $ensurePeriodIsOpen,
    ) {}

    public function execute(Wallet $wallet, AccountPayableDTO $dto): AccountPayable
    {
        $supplier = $dto->supplierId ? Supplier::query()->validForPayables($wallet->id)->find($dto->supplierId) : null;
        if ($dto->supplierId && ! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Fornecedor ativo inválido.']);
        }
        $expenseId = $supplier?->default_expense_account_id ?? $dto->expenseAccountId;
        $payableId = $supplier?->payable_account_id ?? $dto->payableAccountId;
        $expenseAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'despesa')
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->find($expenseId);

        if (! $expenseAccount) {
            throw ValidationException::withMessages([
                'expense_account_id' => 'Conta de despesa inválida para contas a pagar.',
            ]);
        }

        $payableAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)->where('type', 'passivo')
            ->where('financial_group', 'accounts_payable')->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->when($payableId, fn ($query) => $query->whereKey($payableId))
            ->orderBy('code')->first();
        if (! $payableAccount) {
            throw ValidationException::withMessages(['payable_account_id' => 'Conta de controle do fornecedor inválida.']);
        }

        if ($dto->mode === 'installment') {
            if ($dto->installments !== [] && (count($dto->installments) !== $dto->installmentCount
                || collect($dto->installments)->sum('amount_cents') !== $dto->amountCents)) {
                throw ValidationException::withMessages([
                    'installments' => 'A soma das parcelas precisa ser igual ao valor total.',
                ]);
            }
            $this->ensurePeriodIsOpen->handle($wallet, $dto->competenceDate ?? $dto->dueDate);
        }

        return DB::transaction(function () use ($wallet, $dto, $expenseAccount, $payableAccount, $supplier) {
            if ($dto->mode === 'installment') {
                return $this->createInstallments($wallet, $dto, $expenseAccount, $payableAccount, $supplier);
            }
            $title = AccountPayable::query()->create([
                'wallet_id' => $wallet->id, 'payable_account_id' => $payableAccount->id,
                'supplier_id' => $supplier?->id,
                'expense_account_id' => $expenseAccount->id, 'payee_name' => $supplier?->name ?? $dto->payeeName,
                'description' => $dto->description, 'due_date' => $dto->dueDate,
                'amount_cents' => $dto->amountCents, 'status' => 'pending', 'notes' => $dto->notes,
            ]);
            $provision = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id, 'entry_date' => $dto->dueDate,
                'description' => 'Provisão: '.$dto->description,
                'lines' => [
                    ['chart_of_account_id' => $expenseAccount->id, 'type' => 'debit', 'amount_cents' => $dto->amountCents],
                    ['chart_of_account_id' => $payableAccount->id, 'type' => 'credit', 'amount_cents' => $dto->amountCents],
                ],
            ]);
            $title->update(['provision_journal_entry_id' => $provision->id]);

            return $title->fresh(['expenseAccount', 'payableAccount', 'provisionJournalEntry.lines.chartOfAccount']);
        });
    }

    private function createInstallments(Wallet $wallet, AccountPayableDTO $dto, ChartOfAccount $expenseAccount, ChartOfAccount $payableAccount, ?Supplier $supplier): AccountPayable
    {
        $competenceDate = $dto->competenceDate ?? $dto->dueDate;
        $provision = $this->createJournalEntry->execute([
            'wallet_id' => $wallet->id, 'entry_date' => $competenceDate,
            'description' => 'Provisão total: '.$dto->description,
            'lines' => [
                ['chart_of_account_id' => $expenseAccount->id, 'type' => 'debit', 'amount_cents' => $dto->amountCents],
                ['chart_of_account_id' => $payableAccount->id, 'type' => 'credit', 'amount_cents' => $dto->amountCents],
            ],
        ]);
        $series = FinancialTitleSeries::query()->create([
            'wallet_id' => $wallet->id, 'type' => 'payable', 'mode' => 'installment',
            'description' => $dto->description, 'counterparty' => $supplier?->name ?? $dto->payeeName,
            'total_amount_cents' => $dto->amountCents, 'installment_count' => $dto->installmentCount,
            'competence_date' => $competenceDate, 'provision_journal_entry_id' => $provision->id,
        ]);
        $schedule = $dto->installments !== []
            ? collect($dto->installments)->values()->map(fn (array $item, int $index) => [
                'number' => $index + 1,
                'due_date' => (string) $item['due_date'],
                'amount_cents' => (int) $item['amount_cents'],
            ])->all()
            : $this->installmentSchedule->build($dto->amountCents, $dto->installmentCount, $dto->dueDate, $dto->intervalMonths);
        $titles = collect($schedule)
            ->map(fn (array $item) => AccountPayable::query()->create([
                'wallet_id' => $wallet->id, 'series_id' => $series->id,
                'installment_number' => $item['number'], 'installment_count' => $dto->installmentCount,
                'payable_account_id' => $payableAccount->id, 'supplier_id' => $supplier?->id,
                'expense_account_id' => $expenseAccount->id, 'payee_name' => $series->counterparty,
                'description' => $dto->description, 'due_date' => $item['due_date'],
                'amount_cents' => $item['amount_cents'], 'status' => 'pending', 'notes' => $dto->notes,
            ]));

        return $titles->first()->fresh(['series.payables', 'series.provisionJournalEntry.lines.chartOfAccount']);
    }
}
