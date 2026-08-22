<?php

namespace App\Services\Financial;

use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BuildRecurringFinancialForecastBacktest
{
    private const SAMPLE_SIZE = 12;

    public function execute(Wallet $wallet, RecurringFinancialExpectation $terminalRule): array
    {
        $chain = $this->chain($wallet, $terminalRule);

        if ($terminalRule->amount_mode === 'fixed') {
            return $this->emptyResult(false, 'fixed_amount');
        }

        $variableIds = $chain->where('amount_mode', 'variable')->pluck('id');
        $actuals = RecurringFinancialOccurrence::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('recurring_financial_expectation_id', $variableIds)
            ->where('status', 'confirmed')
            ->whereNotNull('actual_amount_cents')
            ->orderBy('period_date')
            ->orderBy('id')
            ->get(['id', 'period_date', 'actual_amount_cents']);

        $targetCount = max(0, $actuals->count() - 3);
        if ($targetCount === 0) {
            return array_merge($this->emptyResult(true, 'insufficient_history', $actuals->count()), [
                'current_strategy' => $terminalRule->effectiveForecastStrategy(),
                'current_strategy_label' => $terminalRule->forecastStrategyLabel(),
            ]);
        }

        $periodsByStrategy = collect(array_keys(RecurringFinancialExpectation::FORECAST_STRATEGIES))->mapWithKeys(fn (string $code) => [$code => collect()]);
        $history = [];

        foreach ($actuals as $actual) {
            if (count($history) >= 3) {
                $lastThree = array_slice($history, -3);
                foreach (array_keys(RecurringFinancialExpectation::FORECAST_STRATEGIES) as $code) {
                    $forecast = $this->forecast($code, $lastThree);
                    $periodsByStrategy[$code]->push($this->period($actual, $forecast));
                }
            }

            $history[] = $actual->actual_amount_cents;
        }

        $strategies = collect(RecurringFinancialExpectation::FORECAST_STRATEGIES)->map(function (string $label, string $code) use ($periodsByStrategy) {
            $periods = $periodsByStrategy[$code]->take(-self::SAMPLE_SIZE)->values();
            $percentagePeriods = $periods->whereNotNull('variance_bps');

            return [
                'code' => $code,
                'label' => $label,
                'sample_count' => $periods->count(),
                'mean_absolute_variance_cents' => $this->mean($periods->pluck('absolute_variance_cents')),
                'mean_signed_variance_cents' => $this->mean($periods->pluck('variance_cents')),
                'mean_absolute_percentage_error_bps' => $this->mean($percentagePeriods->pluck('variance_bps')->map(fn (int $value) => abs($value))),
                'periods' => $periods->all(),
            ];
        })->values();
        $currentStrategy = $terminalRule->effectiveForecastStrategy();
        $recommended = $strategies->sort(fn (array $left, array $right) => $this->compare($left, $right, $currentStrategy))->first();

        return [
            'applicable' => true,
            'reason' => null,
            'has_sufficient_history' => true,
            'total_eligible_actual_count' => $actuals->count(),
            'backtest_target_count' => $targetCount,
            'sample_target_count' => min(self::SAMPLE_SIZE, $targetCount),
            'current_strategy' => $currentStrategy,
            'current_strategy_label' => $terminalRule->forecastStrategyLabel(),
            'strategies' => $strategies->all(),
            'recommended_strategy' => $recommended['code'],
            'recommended_strategy_label' => $recommended['label'],
            'recommendation_basis' => 'mae',
        ];
    }

    private function chain(Wallet $wallet, RecurringFinancialExpectation $terminalRule): Collection
    {
        if ($terminalRule->wallet_id !== $wallet->id) {
            throw ValidationException::withMessages(['expectation' => 'Recorrência inválida para esta carteira.']);
        }
        if ($terminalRule->successor()->exists()) {
            throw ValidationException::withMessages(['expectation' => 'O backtest exige a versão terminal da recorrência.']);
        }

        $chain = collect();
        $current = $terminalRule;
        while ($current) {
            if ($chain->contains('id', $current->id)) {
                throw ValidationException::withMessages(['expectation' => 'A cadeia da recorrência contém um ciclo.']);
            }
            if ($current->wallet_id !== $wallet->id || $current->type !== $terminalRule->type) {
                throw ValidationException::withMessages(['expectation' => 'A cadeia da recorrência é inválida para esta carteira.']);
            }
            $chain->push($current);
            $current = $current->predecessor()->first();
        }

        return $chain;
    }

    private function forecast(string $code, array $lastThree): int
    {
        if ($code === 'last_actual') {
            return $lastThree[2];
        }
        if ($code === 'median_last_3') {
            sort($lastThree, SORT_NUMERIC);

            return $lastThree[1];
        }

        return $this->divideHalfUp(array_sum($lastThree), 3);
    }

    private function period(RecurringFinancialOccurrence $actual, int $forecast): array
    {
        $variance = $actual->actual_amount_cents - $forecast;

        return [
            'period_date' => $actual->period_date->toDateString(),
            'actual_amount_cents' => $actual->actual_amount_cents,
            'forecast_amount_cents' => $forecast,
            'variance_cents' => $variance,
            'absolute_variance_cents' => abs($variance),
            'variance_bps' => $forecast > 0 ? $this->divideHalfUp($variance * 10000, $forecast) : null,
        ];
    }

    private function compare(array $left, array $right, string $currentStrategy): int
    {
        $comparison = $left['mean_absolute_variance_cents'] <=> $right['mean_absolute_variance_cents'];
        if ($comparison !== 0) {
            return $comparison;
        }
        if ($left['mean_absolute_percentage_error_bps'] !== null && $right['mean_absolute_percentage_error_bps'] !== null) {
            $comparison = $left['mean_absolute_percentage_error_bps'] <=> $right['mean_absolute_percentage_error_bps'];
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        $comparison = abs($left['mean_signed_variance_cents']) <=> abs($right['mean_signed_variance_cents']);

        return $comparison !== 0 ? $comparison : $this->priority($left['code'], $currentStrategy) <=> $this->priority($right['code'], $currentStrategy);
    }

    private function priority(string $code, string $currentStrategy): int
    {
        return $code === $currentStrategy ? 0 : array_search($code, array_keys(RecurringFinancialExpectation::FORECAST_STRATEGIES), true) + 1;
    }

    private function mean(Collection $values): ?int
    {
        return $values->isEmpty() ? null : $this->divideHalfUp((int) $values->sum(), $values->count());
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        return (int) round($numerator / $denominator, 0, PHP_ROUND_HALF_UP);
    }

    private function emptyResult(bool $applicable, string $reason, int $eligibleCount = 0): array
    {
        return [
            'applicable' => $applicable,
            'reason' => $reason,
            'has_sufficient_history' => false,
            'total_eligible_actual_count' => $eligibleCount,
            'backtest_target_count' => 0,
            'sample_target_count' => 0,
            'current_strategy' => null,
            'current_strategy_label' => null,
            'strategies' => [],
            'recommended_strategy' => null,
            'recommended_strategy_label' => null,
            'recommendation_basis' => null,
        ];
    }
}
