<?php

namespace App\DTOs\Accounting;

final readonly class BulkPostPendingEntriesResultDTO
{
    /**
     * @param  list<array{journal_entry_id: int, reason: string}>  $skippedItems
     * @param  list<array{journal_entry_id: int, message: string}>  $errorItems
     */
    public function __construct(
        public int $posted,
        public int $skipped,
        public int $errors,
        public array $skippedItems = [],
        public array $errorItems = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'posted' => $this->posted,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'skipped_items' => $this->skippedItems,
            'error_items' => $this->errorItems,
            'message' => $this->message(),
        ];
    }

    public function message(): string
    {
        return sprintf(
            '%d postados, %d ignorados e %d falhas.',
            $this->posted,
            $this->skipped,
            $this->errors,
        );
    }
}
