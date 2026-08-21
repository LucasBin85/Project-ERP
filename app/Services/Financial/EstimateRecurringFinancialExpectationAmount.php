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
        $expectationIds = [];
        $current = $expectation;
        while ($current && ! in_array($current->id, $expectationIds, true)) {
            $expectationIds[] = $current->id;
            $current = $current->predecessor()->first();
        }

        $amounts = \App\Models\RecurringFinancialOccurrence::query()
            ->whereIn('recurring_financial_expectation_id', $expectationIds)->where('status', 'confirmed')
            ->whereNotNull('actual_amount_cents')->where('period_date', '<', $periodMonth->toDateString())
            ->orderByDesc('period_date')->limit(3)->pluck('actual_amount_cents');

        if ($amounts->isEmpty()) {
            return $expectation->expected_amount_cents;
        }

        return match ($expectation->effectiveForecastStrategy()) {
            'last_actual' => $amounts->first(),
            'median_last_3' => $this->median($amounts->all()),
            default => (int) round($amounts->sum() / $amounts->count(), 0, PHP_ROUND_HALF_UP),
        };
    }

    private function median(array $amounts): int
    {
        sort($amounts, SORT_NUMERIC);
        $count = count($amounts);

        return $count % 2 === 1
            ? $amounts[intdiv($count, 2)]
            : (int) round(($amounts[0] + $amounts[1]) / 2, 0, PHP_ROUND_HALF_UP);
    }
}
