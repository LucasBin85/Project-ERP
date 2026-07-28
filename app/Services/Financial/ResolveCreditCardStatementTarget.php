<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use Carbon\CarbonImmutable;

class ResolveCreditCardStatementTarget
{
    public function execute(CreditCard $card, array $parsed, string $filename): ?array
    {
        $dueDate = $this->date($parsed['due_date'] ?? null);
        $reference = $dueDate;
        $source = $dueDate ? 'due_date' : null;

        if (! $reference && isset($parsed['reference_year'], $parsed['reference_month'])) {
            $reference = CarbonImmutable::create((int) $parsed['reference_year'], (int) $parsed['reference_month'], 1);
            $source = 'file_reference';
        }

        if (! $reference && preg_match('/(?<year>20\d{2})[-_](?<month>0[1-9]|1[0-2])[-_](?<day>0[1-9]|[12]\d|3[01])/', pathinfo($filename, PATHINFO_FILENAME), $match)) {
            $reference = CarbonImmutable::create((int) $match['year'], (int) $match['month'], (int) $match['day']);
            $dueDate = $reference;
            $source = 'filename';
        }

        if (! $reference) {
            return null;
        }

        return [
            'reference_year' => (int) $reference->year,
            'reference_month' => (int) $reference->month,
            'reference' => $reference->format('m/Y'),
            'nominal_due_at' => $dueDate?->toDateString(),
            'source' => $source,
        ];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
