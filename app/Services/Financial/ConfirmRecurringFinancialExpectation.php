<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmRecurringFinancialExpectation
{
    public function __construct(
        private readonly CreateAccountPayable $createAccountPayable,
        private readonly CreateAccountReceivable $createAccountReceivable,
    ) {}

    public function execute(
        Wallet $wallet,
        RecurringFinancialExpectation $expectation,
        string $period,
        int $amountCents,
        ?string $dueDate = null,
        ?string $notes = null,
    ): RecurringFinancialOccurrence {
        abort_unless((int) $expectation->wallet_id === (int) $wallet->id, 404);

        $periodDate = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth();

        if (! $expectation->isApplicableTo($periodDate)) {
            throw ValidationException::withMessages([
                'period' => 'Esta recorrência não está ativa para o período informado.',
            ]);
        }

        if ($amountCents < 1) {
            throw ValidationException::withMessages([
                'amount_cents' => 'Informe um valor maior que zero.',
            ]);
        }

        $resolvedDueDate = $dueDate
            ? CarbonImmutable::parse($dueDate)
            : $expectation->dueDateForPeriod($periodDate);

        if (! $resolvedDueDate->isSameMonth($periodDate)) {
            throw ValidationException::withMessages([
                'due_date' => 'O vencimento precisa pertencer ao período da recorrência.',
            ]);
        }

        return DB::transaction(function () use (
            $wallet,
            $expectation,
            $periodDate,
            $amountCents,
            $resolvedDueDate,
            $notes,
        ) {
            $alreadyExists = RecurringFinancialOccurrence::query()
                ->where('recurring_financial_expectation_id', $expectation->id)
                ->whereDate('period_date', $periodDate->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($alreadyExists) {
                throw ValidationException::withMessages([
                    'period' => 'Este período já possui uma ocorrência registrada.',
                ]);
            }

            $expectation->loadMissing(['supplier', 'customer', 'defaultAccount']);
            $account = $this->validateDefaultAccount($wallet, $expectation);

            if ($expectation->type === 'payable') {
                $supplier = Supplier::query()->validForPayables($wallet->id)->find($expectation->supplier_id);

                if (! $supplier) {
                    throw ValidationException::withMessages([
                        'supplier_id' => 'O fornecedor desta recorrência não está mais disponível para contas a pagar.',
                    ]);
                }

                $title = $this->createAccountPayable->execute($wallet, new AccountPayableDTO(
                    expenseAccountId: $account->id,
                    payeeName: $supplier->name,
                    description: $expectation->description,
                    dueDate: $resolvedDueDate->toDateString(),
                    amountCents: $amountCents,
                    notes: $notes ?: $expectation->notes,
                    payableAccountId: $supplier->payable_account_id,
                    supplierId: null,
                ));

                $title->update(['supplier_id' => $supplier->id]);

                return RecurringFinancialOccurrence::query()->create([
                    'wallet_id' => $wallet->id,
                    'recurring_financial_expectation_id' => $expectation->id,
                    'period_date' => $periodDate->toDateString(),
                    'due_date' => $resolvedDueDate->toDateString(),
                    'expected_amount_cents' => $expectation->expected_amount_cents,
                    'actual_amount_cents' => $amountCents,
                    'status' => 'confirmed',
                    'account_payable_id' => $title->id,
                    'confirmed_at' => now(),
                    'notes' => $notes,
                ]);
            }

            $customer = Customer::query()->validForReceivables($wallet->id)->find($expectation->customer_id);

            if (! $customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'O cliente desta recorrência não está mais disponível para contas a receber.',
                ]);
            }

            $title = $this->createAccountReceivable->execute($wallet, new AccountReceivableDTO(
                revenueAccountId: $account->id,
                customerName: $customer->name,
                description: $expectation->description,
                dueDate: $resolvedDueDate->toDateString(),
                amountCents: $amountCents,
                notes: $notes ?: $expectation->notes,
                receivableAccountId: $customer->receivable_account_id,
                customerId: null,
            ));

            $title->update(['customer_id' => $customer->id]);

            return RecurringFinancialOccurrence::query()->create([
                'wallet_id' => $wallet->id,
                'recurring_financial_expectation_id' => $expectation->id,
                'period_date' => $periodDate->toDateString(),
                'due_date' => $resolvedDueDate->toDateString(),
                'expected_amount_cents' => $expectation->expected_amount_cents,
                'actual_amount_cents' => $amountCents,
                'status' => 'confirmed',
                'account_receivable_id' => $title->id,
                'confirmed_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    private function validateDefaultAccount(
        Wallet $wallet,
        RecurringFinancialExpectation $expectation,
    ): ChartOfAccount {
        $type = $expectation->type === 'payable' ? 'despesa' : 'receita';

        $account = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', $type)
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->find($expectation->default_account_id);

        if (! $account) {
            throw ValidationException::withMessages([
                'default_account_id' => 'A conta padrão desta recorrência não é mais válida.',
            ]);
        }

        return $account;
    }
}
