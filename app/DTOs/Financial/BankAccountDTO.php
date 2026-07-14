<?php

namespace App\DTOs\Financial;

final readonly class BankAccountDTO
{
    public function __construct(
        public int $bankId,
        public string $name,
        public string $accountType,
        public int $openingBalanceCents,
        public ?string $agency = null,
        public ?string $accountNumber = null,
        public ?string $openingBalanceDate = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            bankId: (int) $data['bank_id'],
            name: trim((string) $data['name']),
            accountType: (string) $data['account_type'],
            openingBalanceCents: (int) ($data['opening_balance_cents'] ?? 0),
            agency: self::nullableString($data['agency'] ?? null),
            accountNumber: self::nullableString($data['account_number'] ?? null),
            openingBalanceDate: self::nullableString($data['opening_balance_date'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
