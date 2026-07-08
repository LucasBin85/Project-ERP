<?php

namespace App\DTOs\Financial;

final readonly class PayAccountPayableDTO
{
    public function __construct(
        public int $bankAccountId,
        public string $paidAt,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            bankAccountId: (int) $data['bank_account_id'],
            paidAt: (string) $data['paid_at'],
        );
    }
}
