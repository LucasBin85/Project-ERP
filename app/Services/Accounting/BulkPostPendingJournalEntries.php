<?php

namespace App\Services\Accounting;

use App\DTOs\Accounting\BulkPostPendingEntriesResultDTO;
use App\Models\JournalEntry;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BulkPostPendingJournalEntries
{
    public function __construct(
        private readonly AssessJournalEntryPostingReadiness $readiness,
        private readonly BuildPendingJournalEntries $pendingEntries,
        private readonly PostJournalEntry $postJournalEntry,
    ) {}

    /**
     * @param  list<int>  $entryIds
     */
    public function selected(Wallet $wallet, array $entryIds): BulkPostPendingEntriesResultDTO
    {
        $ids = collect($entryIds)
            ->map(fn ($entryId) => (int) $entryId)
            ->filter(fn (int $entryId) => $entryId > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new InvalidArgumentException('Selecione ao menos um lançamento para postar.');
        }

        return $this->process($wallet, $ids);
    }

    public function allReady(Wallet $wallet): BulkPostPendingEntriesResultDTO
    {
        $ids = $this->pendingEntries
            ->readyEntries($wallet)
            ->pluck('id')
            ->map(fn ($entryId) => (int) $entryId)
            ->values();

        return $this->process($wallet, $ids);
    }

    /**
     * @param  Collection<int, int>  $entryIds
     */
    private function process(
        Wallet $wallet,
        Collection $entryIds,
    ): BulkPostPendingEntriesResultDTO {
        $posted = 0;
        $skippedItems = [];
        $errorItems = [];

        foreach ($entryIds as $entryId) {
            try {
                $outcome = DB::transaction(
                    fn () => $this->processEntry($wallet, $entryId),
                );

                if ($outcome['status'] === 'posted') {
                    $posted++;

                    continue;
                }

                $skippedItems[] = [
                    'journal_entry_id' => $entryId,
                    'reason' => $outcome['reason'],
                ];
            } catch (Throwable $exception) {
                if (! $exception instanceof RuntimeException
                    && ! $exception instanceof InvalidArgumentException) {
                    report($exception);
                }

                $errorItems[] = [
                    'journal_entry_id' => $entryId,
                    'message' => $exception instanceof RuntimeException
                        || $exception instanceof InvalidArgumentException
                            ? $exception->getMessage()
                            : 'Não foi possível postar este lançamento.',
                ];
            }
        }

        return new BulkPostPendingEntriesResultDTO(
            posted: $posted,
            skipped: count($skippedItems),
            errors: count($errorItems),
            skippedItems: $skippedItems,
            errorItems: $errorItems,
        );
    }

    /** @return array{status: 'posted'|'skipped', reason?: string} */
    private function processEntry(Wallet $wallet, int $entryId): array
    {
        $entry = JournalEntry::query()
            ->whereKey($entryId)
            ->where('wallet_id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (! $entry) {
            return $this->skipped('O lançamento não está disponível na wallet ativa.');
        }

        $lines = $entry->lines()
            ->with('chartOfAccount.children')
            ->lockForUpdate()
            ->get();
        $entry->setRelation('lines', $lines);
        $readiness = $this->readiness->handle($wallet, $entry);

        if (! $readiness->ready) {
            return $this->skipped($readiness->reason ?? 'O lançamento não está pronto para postagem.');
        }

        $this->postJournalEntry->handle($entry);

        return ['status' => 'posted'];
    }

    /** @return array{status: 'skipped', reason: string} */
    private function skipped(string $reason): array
    {
        return [
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }
}
