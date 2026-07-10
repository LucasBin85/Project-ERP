<?php

namespace App\DTOs\Financial;

final readonly class ParsedOfxTransactionDTO
{
    public function __construct(
        public string $fitId,
        public string $postedAt,
        public int $amountCents,
        public string $direction,
        public string $description,
        public array $raw = [],
    ) {
    }
}
