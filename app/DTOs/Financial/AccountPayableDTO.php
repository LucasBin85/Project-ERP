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
        public ?int $payableAccountId = null,
        public ?int $supplierId = null,
        public string $mode = 'single',
        public int $installmentCount = 1,
        public int $intervalMonths = 1,
        public ?string $competenceDate = null,
        public array $installments = [],
        public bool $preferExplicitExpenseAccount = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            expenseAccountId: (int) ($data['expense_account_id'] ?? 0),
            payeeName: trim((string) ($data['payee_name'] ?? '')),
            description: trim((string) $data['description']),
            dueDate: (string) $data['due_date'],
            amountCents: (int) $data['amount_cents'],
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
            payableAccountId: isset($data['payable_account_id']) ? (int) $data['payable_account_id'] : null,
            supplierId: isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            mode: (string) ($data['mode'] ?? 'single'),
            installmentCount: (int) ($data['installment_count'] ?? 1),
            intervalMonths: (int) ($data['interval_months'] ?? 1),
            competenceDate: $data['competence_date'] ?? null,
            installments: array_values($data['installments'] ?? []),
        );
    }
}
