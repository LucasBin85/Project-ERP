<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\FinancialTitleSeries;

class RefreshFinancialTitleSeriesStatus
{
    public function execute(FinancialTitleSeries $series): void
    {
        $series = FinancialTitleSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();
        $titles = match ($series->type) {
            'payable' => AccountPayable::query()->where('series_id', $series->id),
            'receivable' => AccountReceivable::query()->where('series_id', $series->id),
        };
        $settledStatus = $series->type === 'payable' ? 'paid' : 'received';
        $hasPending = (clone $titles)->where('status', 'pending')->exists();
        $hasSettled = (clone $titles)->where('status', $settledStatus)->exists();
        $allCancelled = ! (clone $titles)->where('status', '!=', 'cancelled')->exists();

        $status = match (true) {
            $allCancelled => 'cancelled',
            $hasPending && ! $hasSettled => 'pending',
            $hasPending && $hasSettled => 'partially_settled',
            $hasSettled => 'settled',
            default => 'pending',
        };

        $series->update(['status' => $status]);
    }
}
