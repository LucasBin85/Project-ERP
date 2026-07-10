<?php

namespace App\DTOs\Financial;

final readonly class BankReconciliationDTO
{
    /**
     * @param array<int, array{transaction_date: string, description: string, amount_cents: int, journal_line_id?: int|null, bank_statement_import_transaction_id?: int|null}> $statementItems
     */
    public function __construct(
        public int $bankAccountId,
        public string $periodStart,
        public string $periodEnd,
        public int $statementBalanceCents,
        public array $statementItems = [],
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
            statementItems: collect($data['statement_items'] ?? [])
                ->map(fn (array $item) => [
                    'bank_statement_import_transaction_id' => filled($item['bank_statement_import_transaction_id'] ?? null) ? (int) $item['bank_statement_import_transaction_id'] : null,
                    'transaction_date' => (string) $item['transaction_date'],
                    'description' => trim((string) $item['description']),
                    'amount_cents' => (int) $item['amount_cents'],
                    'journal_line_id' => filled($item['journal_line_id'] ?? null) ? (int) $item['journal_line_id'] : null,
                ])
                ->values()
                ->all(),
            notes: isset($data['notes']) ? trim((string) $data['notes']) : null,
        );
    }
}
