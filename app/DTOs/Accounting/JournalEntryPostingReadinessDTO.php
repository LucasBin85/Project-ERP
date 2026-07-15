<?php

namespace App\DTOs\Accounting;

final readonly class JournalEntryPostingReadinessDTO
{
    public function __construct(
        public bool $ready,
        public ?string $reason = null,
    ) {}

    public static function ready(): self
    {
        return new self(true);
    }

    public static function pending(string $reason): self
    {
        return new self(false, $reason);
    }
}
