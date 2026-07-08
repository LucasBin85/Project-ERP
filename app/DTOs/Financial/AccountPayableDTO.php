<?php

namespace App\DTOs\Financial;

final readonly class AccountPayableDTO
{
    public function __construct(
        public int $expenseAccountId,
        public string $payeeName,
        public string $description,
        public string $dueDate,
        public int $amountCents,
        public ?string $notes = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            expenseAccountId: (int) $data['expense_account_id'],
            payeeName: trim((string) $data['payee_name']),
            description: trim((string) $data['description']),
            dueDate: (string) $data['due_date'],
            amountCents: (int) $data['amount_cents'],
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
        );
    }
}
