<?php

namespace App\DTOs\Financial;

final readonly class BankStatementFiltersDTO
{
    public function __construct(
        public ?int $bankAccountId,
        public ?string $startDate,
        public ?string $endDate,
        public string $search = '',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            bankAccountId: isset($data['bank_account_id']) && $data['bank_account_id'] !== ''
                ? (int) $data['bank_account_id']
                : null,
            startDate: $data['start_date'] ?? null,
            endDate: $data['end_date'] ?? null,
            search: trim((string) ($data['search'] ?? '')),
        );
    }

    public function isReady(): bool
    {
        return $this->bankAccountId !== null
            && filled($this->startDate)
            && filled($this->endDate);
    }

    public function toArray(): array
    {
        return [
            'bank_account_id' => $this->bankAccountId ? (string) $this->bankAccountId : '',
            'start_date' => $this->startDate ?? '',
            'end_date' => $this->endDate ?? '',
            'search' => $this->search,
        ];
    }
}
