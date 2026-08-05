<?php

namespace App\Services\Financial;

use Illuminate\Support\Str;

class DetectCreditCardInstallment
{
    public function execute(string $description): ?array
    {
        $patterns = [
            '/\bparcela\s*(\d{1,2})\s*\/\s*(\d{1,2})\b/iu',
            '/\b(?:parcela\s*)?(\d{1,2})\s+de\s+(\d{1,2})\b/iu',
            '/\b(\d{1,2})\s*\/\s*(\d{1,2})\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $description, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $number = (int) $match[1][0];
            $total = (int) $match[2][0];
            if ($number < 1 || $total < 2 || $number > $total || $total > 60) {
                continue;
            }
            $base = trim(preg_replace($pattern, ' ', $description) ?? $description, " \t\n\r\0\x0B-–—|");
            $base = preg_replace('/\s+/u', ' ', $base) ?: $base;

            return [
                'installment_number' => $number,
                'installments_total' => $total,
                'description_base' => $base,
                'normalized_description' => $this->normalize($base),
                'started_before_erp' => $number > 1,
            ];
        }

        return null;
    }

    public function normalize(string $description): string
    {
        $value = Str::ascii(Str::lower($description));
        $value = preg_replace('/\b\d{6,}\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
