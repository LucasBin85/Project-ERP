<?php

namespace App\Services\Financial;

use App\Models\RecurringFinancialExpectation;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class BuildRecurringFinancialRulesOverview
{
    public function __construct(
        private readonly EstimateRecurringFinancialExpectationAmount $estimate,
        private readonly BuildRecurringFinancialRulePerformance $performance,
    ) {}

    public function execute(Wallet $wallet, string $type, CarbonInterface $referenceDate): array
    {
        $reference = CarbonImmutable::instance($referenceDate)->startOfMonth();

        return RecurringFinancialExpectation::query()->where('wallet_id', $wallet->id)->where('type', $type)
            ->where('status', 'active')->whereDoesntHave('successor')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $reference))
            ->with(['supplier:id,name', 'customer:id,name', 'defaultAccount:id,code,name'])
            ->orderBy('description')->get()->map(function (RecurringFinancialExpectation $rule) use ($wallet, $reference) {
                $lastResolved = $rule->occurrences()->max('period_date');
                $minimum = collect([
                    $reference,
                    CarbonImmutable::instance($rule->starts_on)->startOfMonth(),
                    $lastResolved ? CarbonImmutable::parse($lastResolved)->addMonth()->startOfMonth() : null,
                ])->filter()->max();
                $period = $reference;
                for ($i = 0; $i < 240; $i++, $period = $period->addMonth()) {
                    if ($rule->isApplicableTo($period) && ! $rule->occurrences()->whereDate('period_date', $period)->exists()) {
                        break;
                    }
                }
                $hasNext = $i < 240 && $rule->isApplicableTo($period);

                return [
                    'id' => $rule->id, 'description' => $rule->description,
                    'counterparty' => $rule->type === 'payable' ? $rule->supplier : $rule->customer,
                    'frequency' => $rule->frequency, 'amount_mode' => $rule->amount_mode,
                    'expected_amount_cents' => $rule->expected_amount_cents, 'default_account' => $rule->defaultAccount,
                    'starts_on' => $rule->starts_on->toDateString(), 'ends_on' => $rule->ends_on?->toDateString(),
                    'due_day' => $rule->due_day, 'notes' => $rule->notes,
                    'next_period_date' => $hasNext ? $period->toDateString() : null,
                    'next_due_date' => $hasNext ? $rule->dueDateForPeriod($period)->toDateString() : null,
                    'next_expected_amount_cents' => $hasNext ? $this->estimate->execute($rule, $period) : null,
                    'minimum_revision_period' => $minimum->toDateString(), 'state' => 'active',
                    'performance' => $this->performance->execute($wallet, $rule),
                ];
            })->values()->all();
    }
}
