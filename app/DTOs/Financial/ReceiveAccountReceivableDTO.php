<?php

namespace App\DTOs\Financial;

final readonly class ReceiveAccountReceivableDTO
{
    public function __construct(
        public int $bankAccountId,
        public string $receivedAt,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            bankAccountId: (int) $data['bank_account_id'],
            receivedAt: (string) $data['received_at'],
        );
    }
}
