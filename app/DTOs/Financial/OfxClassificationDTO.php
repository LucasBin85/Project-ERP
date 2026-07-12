<?php

namespace App\DTOs\Financial;

final readonly class OfxClassificationDTO
{
    public function __construct(
        public int $destinationAccountId,
        public bool $shouldPost = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            destinationAccountId: (int) $data['chart_of_account_id'],
            shouldPost: (bool) ($data['should_post'] ?? false),
        );
    }
}
