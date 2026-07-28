<?php

namespace App\Services\Financial;

use Carbon\CarbonImmutable;

class InstallmentSchedule
{
    /** @return array<int, array{number: int, due_date: string, amount_cents: int}> */
    public function build(int $totalAmountCents, int $count, string $firstDueDate, int $intervalMonths = 1): array
    {
        $base = intdiv($totalAmountCents, $count);
        $remainder = $totalAmountCents % $count;
        $first = CarbonImmutable::parse($firstDueDate);

        return collect(range(1, $count))->map(fn (int $number) => [
            'number' => $number,
            'due_date' => $first->addMonthsNoOverflow(($number - 1) * $intervalMonths)->toDateString(),
            'amount_cents' => $base + ($number <= $remainder ? 1 : 0),
        ])->all();
    }
}
