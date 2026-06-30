<?php

namespace App\Data\Accounting;

use Illuminate\Contracts\Support\Arrayable;

class IncomeStatementData implements Arrayable
{
    public function __construct(
        public readonly ReportSectionData $revenues,
        public readonly ReportSectionData $expenses,
    ) {}

    public function revenueCents(): int
    {
        return $this->revenues->totalCents;
    }

    public function expenseCents(): int
    {
        return $this->expenses->totalCents;
    }

    public function netIncomeCents(): int
    {
        return $this->revenueCents() - $this->expenseCents();
    }

    public function toArray(): array
    {
        return [
            'sections' => [
                $this->revenues->toArray(),
                $this->expenses->toArray(),
            ],
            'totals' => [
                'revenue_cents' => $this->revenueCents(),
                'expense_cents' => $this->expenseCents(),
                'net_income_cents' => $this->netIncomeCents(),
            ],
        ];
    }
}