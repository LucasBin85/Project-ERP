<?php

namespace App\Services\Financial;

use App\Models\RecurringFinancialExpectation;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ListRecurringFinancialExpectationsForRange
{
    public function __construct(
        private readonly EstimateRecurringFinancialExpectationAmount $estimateAmount,
    ) {}

    public function execute(Wallet $wallet, string $type, CarbonInterface $start, CarbonInterface $end): array
    {
        $startDate = CarbonImmutable::instance($start)->startOfDay();
        $endDate = CarbonImmutable::instance($end)->endOfDay();
        $firstPeriod = $startDate->startOfMonth();
        $lastPeriod = $endDate->startOfMonth();

        $expectations = RecurringFinancialExpectation::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', $type)
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $endDate)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $firstPeriod))
            ->with([
                'supplier:id,name',
                'customer:id,name',
                'defaultAccount:id,code,name',
                'occurrences' => fn ($query) => $query
                    ->whereBetween('period_date', [$firstPeriod->toDateString(), $lastPeriod->toDateString()])
                    ->select('id', 'recurring_financial_expectation_id', 'period_date', 'status'),
            ])->get();

        return $expectations->flatMap(function (RecurringFinancialExpectation $expectation) use ($firstPeriod, $lastPeriod, $startDate, $endDate) {
            $resolvedPeriods = $expectation->occurrences
                ->keyBy(fn ($occurrence) => $occurrence->period_date->toDateString());

            return $this->periods($firstPeriod, $lastPeriod)
                ->filter(fn (CarbonImmutable $period) => $expectation->isApplicableTo($period))
                ->reject(fn (CarbonImmutable $period) => $resolvedPeriods->has($period->toDateString()))
                ->map(function (CarbonImmutable $period) use ($expectation, $startDate, $endDate) {
                    $dueDate = $expectation->dueDateForPeriod($period);
                    if ($dueDate->lt($startDate) || $dueDate->gt($endDate)) {
                        return null;
                    }

                    $counterparty = $expectation->type === 'payable' ? $expectation->supplier : $expectation->customer;

                    return [
                        'expectation_id' => $expectation->id,
                        'period_date' => $period->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                        'type' => $expectation->type,
                        'description' => $expectation->description,
                        'counterparty' => $counterparty ? ['id' => $counterparty->id, 'name' => $counterparty->name] : null,
                        'default_account' => $expectation->defaultAccount ? [
                            'id' => $expectation->defaultAccount->id,
                            'code' => $expectation->defaultAccount->code,
                            'name' => $expectation->defaultAccount->name,
                        ] : null,
                        'frequency' => $expectation->frequency,
                        'amount_mode' => $expectation->amount_mode,
                        'expected_amount_cents' => $this->estimateAmount->execute($expectation, $period),
                        'is_overdue' => $dueDate->isBefore(today()),
                    ];
                })->filter();
        })->sortBy(['due_date', 'expectation_id'])->values()->all();
    }

    private function periods(CarbonImmutable $first, CarbonImmutable $last): Collection
    {
        $periods = collect();
        for ($period = $first; $period->lte($last); $period = $period->addMonth()) {
            $periods->push($period);
        }

        return $periods;
    }
}
