<?php

namespace App\Services\Accounting;

use App\Models\MonthlyWalletClosing;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class EnsureAccountingPeriodIsOpen
{
    public const MESSAGE = 'Este mês está fechado. Reabra o período para fazer alterações.';

    public function handle(Wallet $wallet, string|CarbonInterface $date): void
    {
        $date = $date instanceof CarbonInterface ? $date : CarbonImmutable::parse($date);
        $closed = MonthlyWalletClosing::query()->where('wallet_id', $wallet->id)
            ->where('year', $date->year)->where('month', $date->month)->where('status', 'closed')->exists();

        if ($closed) {
            throw ValidationException::withMessages(['period' => self::MESSAGE]);
        }
    }
}
