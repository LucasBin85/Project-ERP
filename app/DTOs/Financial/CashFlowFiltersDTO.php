<?php

namespace App\DTOs\Financial;

final readonly class CashFlowFiltersDTO
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $mode = 'all',
        public string $search = '',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: (string) $data['start_date'],
            endDate: (string) $data['end_date'],
            mode: (string) ($data['mode'] ?? 'all'),
            search: trim((string) ($data['search'] ?? '')),
        );
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'mode' => $this->mode,
            'search' => $this->search,
        ];
    }
}
