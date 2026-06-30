<?php

namespace App\Data\Accounting;

use Illuminate\Contracts\Support\Arrayable;

class BalanceSheetData implements Arrayable
{
    public function __construct(
        public readonly ReportSectionData $assets,
        public readonly ReportSectionData $liabilities,
        public readonly ReportSectionData $equity,
        public readonly int $currentPeriodResultCents = 0,
    ) {}

    public function assetsCents(): int
    {
        return $this->assets->totalCents;
    }

    public function liabilitiesCents(): int
    {
        return $this->liabilities->totalCents;
    }

    public function equityCents(): int
    {
        return $this->equity->totalCents;
    }

    public function liabilitiesAndEquityCents(): int
    {
        return $this->liabilitiesCents() + $this->equityCents();
    }

    public function differenceCents(): int
    {
        return $this->assetsCents() - $this->liabilitiesAndEquityCents();
    }

    public function toArray(): array
    {
        return [
            'sections' => [
                $this->assets->toArray(),
                $this->liabilities->toArray(),
                $this->equity->toArray(),
            ],
            'totals' => [
                'assets_cents' => $this->assetsCents(),
                'liabilities_cents' => $this->liabilitiesCents(),
                'equity_cents' => $this->equityCents(),
                'current_period_result_cents' => $this->currentPeriodResultCents,
                'liabilities_and_equity_cents' => $this->liabilitiesAndEquityCents(),
                'difference_cents' => $this->differenceCents(),
            ],
        ];
    }
}