<?php

namespace App\DTOs\Financial;

final readonly class AccountReceivableDTO
{
    public function __construct(
        public int $revenueAccountId,
        public string $customerName,
        public string $description,
        public string $dueDate,
        public int $amountCents,
        public ?string $notes = null,
        public ?int $receivableAccountId = null,
        public ?int $customerId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            revenueAccountId: (int) ($data['revenue_account_id'] ?? 0),
            customerName: trim((string) ($data['customer_name'] ?? '')),
            description: trim((string) $data['description']),
            dueDate: (string) $data['due_date'],
            amountCents: (int) $data['amount_cents'],
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
            receivableAccountId: isset($data['receivable_account_id']) ? (int) $data['receivable_account_id'] : null,
            customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
        );
    }
}
