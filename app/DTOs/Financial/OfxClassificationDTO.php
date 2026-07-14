<?php

namespace App\DTOs\Financial;

final readonly class OfxClassificationDTO
{
    public function __construct(
        public string $operationType,
        public ?int $destinationAccountId = null,
        public bool $shouldPost = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            operationType: (string) $data['operation_type'],
            destinationAccountId: filled($data['chart_of_account_id'] ?? null)
                ? (int) $data['chart_of_account_id']
                : null,
            shouldPost: (bool) ($data['should_post'] ?? false),
        );
    }
}
