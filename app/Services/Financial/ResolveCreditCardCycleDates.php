<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use Carbon\CarbonImmutable;

class ResolveCreditCardCycleDates
{
    public function forReference(CreditCard $card, int $year, int $month, ?string $nominalDueDate = null): array
    {
        $reference = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $closesAt = $this->dateForDay($reference, (int) $card->closing_day);
        $previousClose = $this->dateForDay($reference->subMonthNoOverflow(), (int) $card->closing_day);
        $nominalDueAt = $nominalDueDate
            ? CarbonImmutable::parse($nominalDueDate)->startOfDay()
            : $this->nominalDueDate($closesAt, (int) $card->due_day);

        return [
            'starts_at' => $previousClose->addDay(),
            'closes_at' => $closesAt,
            'reference_year' => $year,
            'reference_month' => $month,
            'nominal_due_at' => $nominalDueAt,
            'due_at' => $this->nextBusinessDay($nominalDueAt),
        ];
    }

    public function forPurchaseDate(CreditCard $card, string $purchaseDate): array
    {
        $purchase = CarbonImmutable::parse($purchaseDate)->startOfDay();
        $close = $this->dateForDay($purchase, (int) $card->closing_day);
        $reference = $purchase->lessThanOrEqualTo($close) ? $purchase : $purchase->addMonthNoOverflow();

        return $this->forReference($card, (int) $reference->year, (int) $reference->month);
    }

    private function dateForDay(CarbonImmutable $date, int $day): CarbonImmutable
    {
        return $date->setDay(min($day, $date->daysInMonth))->startOfDay();
    }

    private function nominalDueDate(CarbonImmutable $closesAt, int $dueDay): CarbonImmutable
    {
        $dueMonth = $dueDay > $closesAt->day ? $closesAt : $closesAt->addMonthNoOverflow();

        return $this->dateForDay($dueMonth, $dueDay);
    }

    private function nextBusinessDay(CarbonImmutable $date): CarbonImmutable
    {
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }
}
