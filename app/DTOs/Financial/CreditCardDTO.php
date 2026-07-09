<?php

namespace App\DTOs\Financial;

final readonly class CreditCardDTO
{
    public function __construct(
        public string $name,
        public string $issuerName,
        public string $network,
        public string $cardType,
        public int $closingDay,
        public int $dueDay,
        public int $bestPurchaseDay,
        public int $creditLimitCents,
        public ?int $parentCardId = null,
        public ?string $holderName = null,
        public ?string $lastFour = null,
        public ?string $notes = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) $data['name']),
            issuerName: trim((string) $data['issuer_name']),
            network: (string) ($data['network'] ?? 'other'),
            cardType: (string) ($data['card_type'] ?? 'main'),
            closingDay: (int) $data['closing_day'],
            dueDay: (int) $data['due_day'],
            bestPurchaseDay: (int) ($data['best_purchase_day'] ?? self::suggestBestPurchaseDay((int) $data['closing_day'])),
            creditLimitCents: (int) ($data['credit_limit_cents'] ?? 0),
            parentCardId: isset($data['parent_card_id']) && $data['parent_card_id'] !== '' ? (int) $data['parent_card_id'] : null,
            holderName: isset($data['holder_name']) ? trim((string) $data['holder_name']) : null,
            lastFour: isset($data['last_four']) ? trim((string) $data['last_four']) : null,
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
        );
    }

    public static function suggestBestPurchaseDay(int $closingDay): int
    {
        return $closingDay >= 28 ? 1 : $closingDay + 1;
    }
}
