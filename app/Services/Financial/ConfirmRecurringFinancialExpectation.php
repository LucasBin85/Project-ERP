<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmRecurringFinancialExpectation
{
    public function __construct(
        private readonly EstimateRecurringFinancialExpectationAmount $estimateAmount,
        private readonly CreateAccountPayable $createAccountPayable,
        private readonly CreateAccountReceivable $createAccountReceivable,
    ) {}

    public function execute(
        Wallet $wallet,
        RecurringFinancialExpectation $expectation,
        CarbonInterface $period,
        int $actualAmountCents,
        ?CarbonInterface $dueDate = null,
        ?string $notes = null,
    ): RecurringFinancialOccurrence {
        return DB::transaction(function () use ($wallet, $expectation, $period, $actualAmountCents, $dueDate, $notes) {
            $locked = RecurringFinancialExpectation::query()->lockForUpdate()->find($expectation->id);
            if (! $locked || $locked->wallet_id !== $wallet->id) {
                throw ValidationException::withMessages(['expectation' => 'Recorrência inválida para esta carteira.']);
            }

            $periodMonth = CarbonImmutable::instance($period)->startOfMonth();
            $this->validateResolution($locked, $periodMonth);

            if ($actualAmountCents < 1) {
                throw ValidationException::withMessages(['amount_cents' => 'O valor realizado deve ser positivo.']);
            }
            if ($locked->amount_mode === 'fixed' && $actualAmountCents !== $locked->expected_amount_cents) {
                throw ValidationException::withMessages(['amount_cents' => 'O valor realizado deve ser igual ao valor fixo da recorrência.']);
            }

            $resolvedDueDate = $dueDate
                ? CarbonImmutable::instance($dueDate)->startOfDay()
                : $locked->dueDateForPeriod($periodMonth);
            if (! $resolvedDueDate->isSameMonth($periodMonth, true)) {
                throw ValidationException::withMessages(['due_date' => 'O vencimento deve pertencer à mesma competência.']);
            }

            $expectedAmountCents = $this->estimateAmount->execute($locked, $periodMonth);
            $title = match ($locked->type) {
                'payable' => $this->createPayable($wallet, $locked, $resolvedDueDate, $actualAmountCents, $notes),
                'receivable' => $this->createReceivable($wallet, $locked, $resolvedDueDate, $actualAmountCents, $notes),
                default => throw ValidationException::withMessages(['type' => 'Tipo de recorrência inválido.']),
            };

            return RecurringFinancialOccurrence::query()->create([
                'wallet_id' => $wallet->id,
                'recurring_financial_expectation_id' => $locked->id,
                'period_date' => $periodMonth,
                'due_date' => $resolvedDueDate,
                'expected_amount_cents' => $expectedAmountCents,
                'actual_amount_cents' => $actualAmountCents,
                'status' => 'confirmed',
                'account_payable_id' => $locked->type === 'payable' ? $title->id : null,
                'account_receivable_id' => $locked->type === 'receivable' ? $title->id : null,
                'confirmed_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    private function validateResolution(RecurringFinancialExpectation $expectation, CarbonImmutable $period): void
    {
        if (! $expectation->isActive()) {
            throw ValidationException::withMessages(['expectation' => 'A recorrência está inativa.']);
        }
        if (! $expectation->isApplicableTo($period)) {
            throw ValidationException::withMessages(['period' => 'A recorrência não se aplica a esta competência.']);
        }
        if ($expectation->occurrences()->where('period_date', $period->toDateString())->exists()) {
            throw ValidationException::withMessages(['period' => 'Esta competência já foi resolvida.']);
        }
    }

    private function createPayable(
        Wallet $wallet,
        RecurringFinancialExpectation $expectation,
        CarbonImmutable $dueDate,
        int $amountCents,
        ?string $notes,
    ): AccountPayable {
        return $this->createAccountPayable->execute($wallet, new AccountPayableDTO(
            expenseAccountId: $expectation->default_account_id,
            payeeName: $expectation->supplier?->name ?? '',
            description: $expectation->description,
            dueDate: $dueDate->toDateString(),
            amountCents: $amountCents,
            notes: $notes,
            supplierId: $expectation->supplier_id,
            mode: 'single',
            preferExplicitExpenseAccount: true,
        ));
    }

    private function createReceivable(
        Wallet $wallet,
        RecurringFinancialExpectation $expectation,
        CarbonImmutable $dueDate,
        int $amountCents,
        ?string $notes,
    ): AccountReceivable {
        return $this->createAccountReceivable->execute($wallet, new AccountReceivableDTO(
            revenueAccountId: $expectation->default_account_id,
            customerName: $expectation->customer?->name ?? '',
            description: $expectation->description,
            dueDate: $dueDate->toDateString(),
            amountCents: $amountCents,
            notes: $notes,
            customerId: $expectation->customer_id,
            mode: 'single',
            preferExplicitRevenueAccount: true,
        ));
    }
}
