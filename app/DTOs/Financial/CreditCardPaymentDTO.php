<?php

namespace App\DTOs\Financial;

final readonly class CreditCardPaymentDTO
{
    public function __construct(
        public int $creditCardId,
        public int $creditCardInvoiceId,
        public int $bankAccountId,
        public string $paymentDate,
        public int $amountCents,
        public ?string $description = null,
        public ?string $notes = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            creditCardId: (int) $data['credit_card_id'],
            creditCardInvoiceId: (int) $data['credit_card_invoice_id'],
            bankAccountId: (int) $data['bank_account_id'],
            paymentDate: (string) $data['payment_date'],
            amountCents: (int) $data['amount_cents'],
            description: isset($data['description']) ? trim((string) $data['description']) : null,
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
        );
    }
}
