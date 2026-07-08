<?php

namespace App\DTOs\Financial;

final readonly class BankTransferDTO
{
    public function __construct(
        public int $fromBankAccountId,
        public int $toBankAccountId,
        public int $amountCents,
        public string $transferDate,
        public string $description,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fromBankAccountId: (int) $data['from_bank_account_id'],
            toBankAccountId: (int) $data['to_bank_account_id'],
            amountCents: (int) $data['amount_cents'],
            transferDate: (string) $data['transfer_date'],
            description: trim((string) $data['description']),
        );
    }
}
