<?php

namespace App\Services\Financial;

use Illuminate\Support\Str;

class NormalizeBankStatementDescription
{
    public function execute(?string $description): string
    {
        $value = Str::lower(Str::ascii((string) $description));
        $value = preg_replace('/\b(?:r\$\s*)?[+-]?\d{1,3}(?:\.\d{3})*,\d{2}\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2}\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d{9,}\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/(.)\1{3,}/u', '$1', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
