<?php

namespace App\Services\Financial;

use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SkipRecurringFinancialExpectation
{
    public function __construct(
        private readonly EstimateRecurringFinancialExpectationAmount $estimateAmount,
    ) {}

    public function execute(
        Wallet $wallet,
        RecurringFinancialExpectation $expectation,
        CarbonInterface $period,
        ?string $notes = null,
    ): RecurringFinancialOccurrence {
        return DB::transaction(function () use ($wallet, $expectation, $period, $notes) {
            $locked = RecurringFinancialExpectation::query()->lockForUpdate()->find($expectation->id);
            if (! $locked || $locked->wallet_id !== $wallet->id) {
                throw ValidationException::withMessages(['expectation' => 'Recorrência inválida para esta carteira.']);
            }

            $periodMonth = CarbonImmutable::instance($period)->startOfMonth();
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['expectation' => 'A recorrência está inativa.']);
            }
            if (! $locked->isApplicableTo($periodMonth)) {
                throw ValidationException::withMessages(['period' => 'A recorrência não se aplica a esta competência.']);
            }
            if ($locked->occurrences()->where('period_date', $periodMonth->toDateString())->exists()) {
                throw ValidationException::withMessages(['period' => 'Esta competência já foi resolvida.']);
            }

            return RecurringFinancialOccurrence::query()->create([
                'wallet_id' => $wallet->id,
                'recurring_financial_expectation_id' => $locked->id,
                'period_date' => $periodMonth,
                'due_date' => $locked->dueDateForPeriod($periodMonth),
                'expected_amount_cents' => $this->estimateAmount->execute($locked, $periodMonth),
                'actual_amount_cents' => null,
                'status' => 'skipped',
                'skipped_at' => now(),
                'notes' => $notes,
            ]);
        });
    }
}
