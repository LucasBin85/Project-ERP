<?php

namespace App\Services\Financial;

use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BuildRecurringFinancialRulePerformance
{
    private const SAMPLE_SIZE = 12;

    public function execute(Wallet $wallet, RecurringFinancialExpectation $terminalRule): array
    {
        if ($terminalRule->wallet_id !== $wallet->id) {
            throw ValidationException::withMessages(['expectation' => 'Recorrência inválida para esta carteira.']);
        }
        if ($terminalRule->successor()->exists()) {
            throw ValidationException::withMessages(['expectation' => 'A análise exige a versão terminal da recorrência.']);
        }

        $chainIds = $this->chainIds($wallet, $terminalRule);
        $occurrences = RecurringFinancialOccurrence::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('recurring_financial_expectation_id', $chainIds)
            ->orderByDesc('period_date')
            ->get();
        $confirmed = $occurrences->where('status', 'confirmed');
        $sample = $confirmed->take(self::SAMPLE_SIZE)->values();
        $periods = $sample->map(fn (RecurringFinancialOccurrence $occurrence) => $this->period($terminalRule, $occurrence));
        $measurable = $periods->where('has_estimate', true)->whereNotNull('actual_amount_cents');
        $percentageMeasurable = $measurable->whereNotNull('variance_bps');

        return [
            'total_confirmed_count' => $confirmed->count(),
            'sample_confirmed_count' => $sample->count(),
            'estimated_confirmed_count' => $measurable->count(),
            'unestimated_confirmed_count' => $sample->count() - $measurable->count(),
            'skipped_total_count' => $occurrences->where('status', 'skipped')->count(),
            'mean_absolute_variance_cents' => $this->mean($measurable->pluck('absolute_variance_cents')),
            'mean_signed_variance_cents' => $this->mean($measurable->pluck('variance_cents')),
            'mean_absolute_percentage_error_bps' => $this->mean($percentageMeasurable->pluck('variance_bps')->map(fn (int $value) => abs($value))),
            'periods' => $periods->all(),
        ];
    }

    private function chainIds(Wallet $wallet, RecurringFinancialExpectation $terminalRule): array
    {
        $ids = [];
        $current = $terminalRule;

        while ($current && ! in_array($current->id, $ids, true)) {
            if ($current->wallet_id !== $wallet->id || $current->type !== $terminalRule->type) {
                throw ValidationException::withMessages(['expectation' => 'A cadeia da recorrência é inválida para esta carteira.']);
            }
            $ids[] = $current->id;
            $current = $current->predecessor()->first();
        }

        return $ids;
    }

    private function period(RecurringFinancialExpectation $terminalRule, RecurringFinancialOccurrence $occurrence): array
    {
        $expected = $occurrence->expected_amount_cents;
        $actual = $occurrence->actual_amount_cents;
        $hasEstimate = $expected !== null;
        $variance = $hasEstimate && $actual !== null ? $actual - $expected : null;
        $varianceBps = $variance !== null && $expected > 0
            ? $this->divideHalfUp($variance * 10000, $expected)
            : null;
        $titleUrl = match (true) {
            $terminalRule->type === 'payable' && $occurrence->account_payable_id !== null => route('accounts-payable.show', $occurrence->account_payable_id),
            $terminalRule->type === 'receivable' && $occurrence->account_receivable_id !== null => route('accounts-receivable.show', $occurrence->account_receivable_id),
            default => null,
        };

        return [
            'occurrence_id' => $occurrence->id,
            'period_date' => $occurrence->period_date->toDateString(),
            'due_date' => $occurrence->due_date?->toDateString(),
            'expected_amount_cents' => $expected,
            'actual_amount_cents' => $actual,
            'has_estimate' => $hasEstimate,
            'variance_cents' => $variance,
            'absolute_variance_cents' => $variance === null ? null : abs($variance),
            'variance_bps' => $varianceBps,
            'account_payable_id' => $occurrence->account_payable_id,
            'account_receivable_id' => $occurrence->account_receivable_id,
            'title_url' => $titleUrl,
        ];
    }

    private function mean(Collection $values): ?int
    {
        return $values->isEmpty() ? null : $this->divideHalfUp((int) $values->sum(), $values->count());
    }

    private function divideHalfUp(int $numerator, int $denominator): int
    {
        return (int) round($numerator / $denominator, 0, PHP_ROUND_HALF_UP);
    }
}
