<?php

namespace App\DTOs\Financial;

final readonly class RecurringFinancialExpectationDTO
{
    public function __construct(
        public string $type,
        public string $description,
        public string $frequency,
        public int $dueDay,
        public string $amountMode,
        public ?int $expectedAmountCents,
        public int $defaultAccountId,
        public string $startsOn,
        public ?string $endsOn = null,
        public ?int $supplierId = null,
        public ?int $customerId = null,
        public ?string $notes = null,
        public ?int $replacesExpectationId = null,
        public ?string $scheduleAnchorDate = null,
        public ?string $forecastStrategy = null,
    ) {}
}
