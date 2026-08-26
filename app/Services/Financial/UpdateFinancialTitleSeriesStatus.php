<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\FinancialTitleSeries;

class UpdateFinancialTitleSeriesStatus
{
    public function execute(FinancialTitleSeries $series): void
    {
        $series = FinancialTitleSeries::query()
            ->whereKey($series->id)
            ->lockForUpdate()
            ->firstOrFail();

        $pending = match ($series->type) {
            'payable' => AccountPayable::query()->where('series_id', $series->id)->where('status', 'pending')->exists(),
            'receivable' => AccountReceivable::query()->where('series_id', $series->id)->where('status', 'pending')->exists(),
        };

        $series->update(['status' => $pending ? 'partially_settled' : 'settled']);
    }
}
