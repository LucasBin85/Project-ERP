<?php

namespace App\Services\Financial;

use App\Models\RecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class EstimateRecurringFinancialExpectationAmount
{
    public function execute(RecurringFinancialExpectation $expectation, CarbonInterface $period): ?int
    {
        if ($expectation->amount_mode === 'fixed') {
            if (! $expectation->expected_amount_cents || $expectation->expected_amount_cents < 1) {
                throw ValidationException::withMessages([
                    'expected_amount_cents' => 'Uma recorrência fixa exige valor positivo.',
                ]);
            }

            return $expectation->expected_amount_cents;
        }

        $periodMonth = CarbonImmutable::instance($period)->startOfMonth();
        $amounts = $expectation->occurrences()->where('status', 'confirmed')
            ->whereNotNull('actual_amount_cents')->where('period_date', '<', $periodMonth->toDateString())
            ->orderByDesc('period_date')->limit(3)->pluck('actual_amount_cents');

        if ($amounts->isEmpty()) {
            return $expectation->expected_amount_cents;
        }

        return (int) round($amounts->sum() / $amounts->count(), 0, PHP_ROUND_HALF_UP);
    }
}
