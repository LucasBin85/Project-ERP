<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankStatementDTO;
use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Models\BankAccount;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BankStatementService
{
    public function build(Wallet $wallet, BankStatementFiltersDTO $filters): BankStatementDTO
    {
        if (! $filters->isReady()) {
            return new BankStatementDTO(
                filters: $filters,
                ready: false,
                bankAccount: null,
                openingBalanceCents: 0,
                totalInflowsCents: 0,
                totalOutflowsCents: 0,
                closingBalanceCents: 0,
                transactions: collect(),
            );
        }

        $bankAccount = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->with('chartOfAccount')
            ->findOrFail($filters->bankAccountId);

        $openingBalanceCents = $this->calculateOpeningBalance($wallet, $bankAccount, $filters);
        $periodLines = $this->periodLines($wallet, $bankAccount, $filters);
        $reconciledLineIds = $this->reconciledLineIds($periodLines->pluck('id'));
        $ofxValidatedLineIds = $this->ofxValidatedLineIds($wallet, $bankAccount, $periodLines);

        $runningBalance = $openingBalanceCents;
        $totalInflowsCents = 0;
        $totalOutflowsCents = 0;

        $transactions = $periodLines
            ->map(function (JournalLine $line) use (
                &$runningBalance,
                &$totalInflowsCents,
                &$totalOutflowsCents,
                $ofxValidatedLineIds,
                $reconciledLineIds,
                $wallet,
            ) {
                $entry = $line->journalEntry;
                $amountCents = (int) $line->amount_cents;
                $inflowCents = $line->type === 'debit' ? $amountCents : 0;
                $outflowCents = $line->type === 'credit' ? $amountCents : 0;

                $totalInflowsCents += $inflowCents;
                $totalOutflowsCents += $outflowCents;
                $runningBalance += $inflowCents;
                $runningBalance -= $outflowCents;

                $classification = $this->classification($wallet, $line);

                return [
                    'id' => $line->id,
                    'date' => $entry?->entry_date,
                    'journal_entry_id' => $entry?->id,
                    'description' => $entry?->description ?: $line->memo,
                    'accounting_status' => $entry?->status,
                    'source' => $entry?->source,
                    'source_label' => $this->sourceLabel($entry?->source),
                    'reconciliation_status' => $this->reconciliationStatus(
                        $line,
                        $ofxValidatedLineIds,
                        $reconciledLineIds,
                    ),
                    'classification_status' => $classification['status'],
                    'classification_label' => $classification['label'],
                    'can_classify' => $entry?->source === 'ofx'
                        && $entry?->status === 'draft'
                        && $entry?->lines->where('chart_of_account_id', $wallet->suspense_account_id)->count() === 1,
                    'type' => $inflowCents > 0 ? 'inflow' : 'outflow',
                    'inflow_cents' => $inflowCents ?: null,
                    'outflow_cents' => $outflowCents ?: null,
                    'amount_cents' => $inflowCents > 0 ? $inflowCents : -$outflowCents,
                    'running_balance_cents' => $runningBalance,
                ];
            })
            ->when(
                $filters->search !== '',
                fn (Collection $transactions) => $transactions->filter(
                    fn (array $transaction) => Str::contains(
                        (string) ($transaction['description'] ?? ''),
                        $filters->search,
                        true,
                    ),
                ),
            )
            ->reverse()
            ->values();

        return new BankStatementDTO(
            filters: $filters,
            ready: true,
            bankAccount: $bankAccount,
            openingBalanceCents: $openingBalanceCents,
            totalInflowsCents: $totalInflowsCents,
            totalOutflowsCents: $totalOutflowsCents,
            closingBalanceCents: $runningBalance,
            transactions: $transactions,
        );
    }

    private function calculateOpeningBalance(Wallet $wallet, BankAccount $bankAccount, BankStatementFiltersDTO $filters): int
    {
        $lines = JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->whereDate('entry_date', '<', $filters->startDate);
            })
            ->get(['type', 'amount_cents']);

        return $this->calculateDebitBalance($lines);
    }

    private function periodLines(Wallet $wallet, BankAccount $bankAccount, BankStatementFiltersDTO $filters): Collection
    {
        return JournalLine::query()
            ->with([
                'journalEntry:id,wallet_id,entry_date,description,status,source',
                'journalEntry.lines:id,journal_entry_id,chart_of_account_id,type,amount_cents,memo',
                'journalEntry.lines.chartOfAccount:id,code,name',
            ])
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->whereDate('entry_date', '>=', $filters->startDate)
                    ->whereDate('entry_date', '<=', $filters->endDate);
            })
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();
    }

    private function reconciledLineIds(Collection $lineIds): Collection
    {
        $lineIds = $lineIds
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($lineIds->isEmpty()) {
            return collect();
        }

        $statementItemIds = BankReconciliationStatementItem::query()
            ->whereIn('journal_line_id', $lineIds)
            ->pluck('journal_line_id');

        $reconciliationItemIds = BankReconciliationItem::query()
            ->whereIn('journal_line_id', $lineIds)
            ->pluck('journal_line_id');

        return $statementItemIds
            ->merge($reconciliationItemIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function ofxValidatedLineIds(Wallet $wallet, BankAccount $bankAccount, Collection $lines): Collection
    {
        $lineIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->values();
        $entryIds = $lines->pluck('journal_entry_id')->map(fn ($id) => (int) $id)->unique()->values();

        if ($lineIds->isEmpty()) {
            return collect();
        }

        $transactions = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->where('status', 'imported')
            ->where(function ($query) use ($entryIds, $lineIds) {
                $query->whereIn('journal_line_id', $lineIds)
                    ->orWhere(function ($query) use ($entryIds) {
                        $query->whereNull('journal_line_id')
                            ->whereIn('journal_entry_id', $entryIds);
                    });
            })
            ->get([
                'journal_entry_id',
                'journal_line_id',
                'posted_at',
                'amount_cents',
                'direction',
            ]);

        return $transactions
            ->map(function (BankStatementImportTransaction $transaction) use ($lines) {
                $matchingLines = $lines->filter(function (JournalLine $line) use ($transaction) {
                    $expectedType = $transaction->direction === 'in' ? 'debit' : 'credit';
                    $isLinkedLine = $transaction->journal_line_id
                        ? (int) $line->id === (int) $transaction->journal_line_id
                        : (int) $line->journal_entry_id === (int) $transaction->journal_entry_id;

                    return $isLinkedLine
                        && $line->type === $expectedType
                        && (int) $line->amount_cents === (int) $transaction->amount_cents
                        && $line->journalEntry?->entry_date?->toDateString() === $transaction->posted_at?->toDateString();
                });

                return $matchingLines->count() === 1
                    ? (int) $matchingLines->first()->id
                    : null;
            })
            ->filter()
            ->unique()
            ->values();
    }

    private function reconciliationStatus(JournalLine $line, Collection $ofxValidatedLineIds, Collection $reconciledLineIds): string
    {
        if ($line->journalEntry?->source === 'ofx') {
            return 'reconciled_via_ofx';
        }

        if ($ofxValidatedLineIds->contains((int) $line->id) || $reconciledLineIds->contains((int) $line->id)) {
            return 'reconciled';
        }

        return 'pending';
    }

    private function classification(Wallet $wallet, JournalLine $bankLine): array
    {
        $counterpartLines = $bankLine->journalEntry?->lines
            ?->reject(fn (JournalLine $line) => (int) $line->id === (int) $bankLine->id)
            ->values() ?? collect();

        $usesSuspenseAccount = $wallet->suspense_account_id
            && $counterpartLines->contains(
                fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id,
            );

        if ($usesSuspenseAccount) {
            return [
                'status' => 'unclassified',
                'label' => 'A classificar',
            ];
        }

        $labels = $counterpartLines
            ->map(fn (JournalLine $line) => $line->chartOfAccount?->name)
            ->filter()
            ->unique()
            ->values();

        return [
            'status' => $labels->isNotEmpty() ? 'classified' : 'unclassified',
            'label' => $labels->isNotEmpty() ? $labels->join(', ') : 'Não identificada',
        ];
    }

    private function sourceLabel(?string $source): string
    {
        return match ($source) {
            'ofx' => 'OFX',
            'open_finance' => 'Open Finance',
            'manual' => 'Manual',
            default => $source ? str($source)->headline()->toString() : 'Manual',
        };
    }

    private function calculateDebitBalance(Collection $lines): int
    {
        return $lines->reduce(function (int $balance, JournalLine $line) {
            $amountCents = (int) $line->amount_cents;

            return $line->type === 'debit'
                ? $balance + $amountCents
                : $balance - $amountCents;
        }, 0);
    }
}
