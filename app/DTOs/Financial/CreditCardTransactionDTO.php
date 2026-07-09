<?php

namespace App\DTOs\Financial;

final readonly class CreditCardTransactionDTO
{
    public function __construct(
        public int $creditCardId,
        public int $expenseAccountId,
        public string $purchaseDate,
        public string $merchantName,
        public string $description,
        public int $amountCents,
        public int $installmentsTotal = 1,
        public int $installmentNumber = 1,
        public ?string $notes = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            creditCardId: (int) $data['credit_card_id'],
            expenseAccountId: (int) $data['expense_account_id'],
            purchaseDate: (string) $data['purchase_date'],
            merchantName: trim((string) $data['merchant_name']),
            description: trim((string) $data['description']),
            amountCents: (int) $data['amount_cents'],
            installmentsTotal: (int) ($data['installments_total'] ?? 1),
            installmentNumber: (int) ($data['installment_number'] ?? 1),
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
        );
    }
}
