<?php

namespace App\DTOs\Financial;

final readonly class DashboardFiltersDTO
{
    public function __construct(
        public string $startDate,
        public string $endDate,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $startDate = (string) ($data['start_date'] ?? now()->startOfMonth()->toDateString());
        $endDate = (string) ($data['end_date'] ?? now()->toDateString());

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return new self(
            startDate: $startDate,
            endDate: $endDate,
        );
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }
}
