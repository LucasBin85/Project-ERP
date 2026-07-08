<?php

namespace App\DTOs\Financial;

final readonly class BankReconciliationDTO
{
    /**
     * @param array<int> $journalLineIds
     */
    public function __construct(
        public int $bankAccountId,
        public string $periodStart,
        public string $periodEnd,
        public int $statementBalanceCents,
        public array $journalLineIds = [],
        public ?string $notes = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            bankAccountId: (int) $data['bank_account_id'],
            periodStart: (string) $data['period_start'],
            periodEnd: (string) $data['period_end'],
            statementBalanceCents: (int) $data['statement_balance_cents'],
            journalLineIds: array_values(array_map('intval', $data['journal_line_ids'] ?? [])),
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
        );
    }
}
