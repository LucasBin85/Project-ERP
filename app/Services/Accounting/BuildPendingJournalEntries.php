<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;
use Illuminate\Support\Collection;

class BuildPendingJournalEntries
{
    public function __construct(
        private readonly AssessJournalEntryPostingReadiness $readiness,
    ) {}

    /** @return list<array<string, mixed>> */
    public function handle(Wallet $wallet): array
    {
        return $this->readyEntries($wallet)
            ->map(fn (JournalEntry $entry) => $this->mapEntry($entry))
            ->values()
            ->all();
    }

    /** @return Collection<int, JournalEntry> */
    public function readyEntries(Wallet $wallet): Collection
    {
        $entries = JournalEntry::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'draft')
            ->with('lines.chartOfAccount.children')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $bankAccountsByChartAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn(
                'chart_of_account_id',
                $entries->flatMap->lines->pluck('chart_of_account_id')->unique(),
            )
            ->get(['id', 'chart_of_account_id', 'name', 'bank_name'])
            ->keyBy('chart_of_account_id');

        return $entries
            ->filter(fn (JournalEntry $entry) => $this->readiness->handle($wallet, $entry)->ready)
            ->each(function (JournalEntry $entry) use ($bankAccountsByChartAccount) {
                $entry->setAttribute(
                    'posting_bank_accounts',
                    $entry->lines
                        ->map(fn ($line) => $bankAccountsByChartAccount->get($line->chart_of_account_id))
                        ->filter()
                        ->unique('id')
                        ->values(),
                );
            })
            ->values();
    }

    /** @return array<string, mixed> */
    private function mapEntry(JournalEntry $entry): array
    {
        /** @var Collection<int, BankAccount> $bankAccounts */
        $bankAccounts = $entry->getAttribute('posting_bank_accounts') ?? collect();
        $debitTotal = (int) $entry->lines->where('type', 'debit')->sum('amount_cents');

        return [
            'id' => $entry->id,
            'entry_date' => $entry->entry_date?->toDateString(),
            'description' => $entry->description ?: "Lançamento #{$entry->id}",
            'source' => $entry->source,
            'source_label' => $this->sourceLabel((string) $entry->source),
            'bank_accounts' => $bankAccounts
                ->map(fn (BankAccount $bankAccount) => [
                    'id' => $bankAccount->id,
                    'name' => $bankAccount->name ?: $bankAccount->bank_name,
                ])
                ->all(),
            'amount_cents' => $debitTotal,
            'status' => 'ready_for_accounting',
            'status_label' => 'Pronto para contabilidade',
            'journal_entry_url' => route('journal-entries.show', $entry),
        ];
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'ofx' => 'OFX',
            'open_finance' => 'Open Finance',
            'manual' => 'Manual',
            default => str($source)->replace('_', ' ')->headline()->toString(),
        };
    }
}
