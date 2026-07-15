<?php

namespace App\DTOs\Financial;

final readonly class BulkPostOfxEntriesResultDTO
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
            '%d lançamentos postados. %d ignorados por pendência. %d falharam.',
            $this->posted,
            $this->skipped,
            $this->errors,
        );
    }
}
