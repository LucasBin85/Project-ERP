<?php

namespace App\Support;

class NormalizedName
{
    public static function display(?string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    }

    public static function key(?string $value): string
    {
        return mb_strtolower(self::display($value), 'UTF-8');
    }
}
