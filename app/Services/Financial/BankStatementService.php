<?php

namespace App\Services\Financial;

use App\DTOs\Financial\BankStatementDTO;
use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Models\BankAccount;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Collection;

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

        $runningBalance = $openingBalanceCents;
        $totalInflowsCents = 0;
        $totalOutflowsCents = 0;

        $transactions = $periodLines
            ->map(function (JournalLine $line) use (&$runningBalance, &$totalInflowsCents, &$totalOutflowsCents, $reconciledLineIds) {
                $entry = $line->journalEntry;
                $amountCents = (int) $line->amount_cents;
                $inflowCents = $line->type === 'debit' ? $amountCents : 0;
                $outflowCents = $line->type === 'credit' ? $amountCents : 0;

                $totalInflowsCents += $inflowCents;
                $totalOutflowsCents += $outflowCents;
                $runningBalance += $inflowCents;
                $runningBalance -= $outflowCents;

                $isReconciled = $reconciledLineIds->contains((int) $line->id);

                return [
                    'id' => $line->id,
                    'date' => $entry?->entry_date,
                    'journal_entry_id' => $entry?->id,
                    'journal_entry_url' => $entry ? route('journal-entries.show', $entry) : null,
                    'description' => $line->memo ?: $entry?->description,
                    'status' => $entry?->status,
                    'source' => $entry?->source,
                    'source_label' => $this->sourceLabel($entry?->source),
                    'reconciliation_status' => $isReconciled ? 'reconciled' : 'pending',
                    'type' => $inflowCents > 0 ? 'inflow' : 'outflow',
                    'inflow_cents' => $inflowCents ?: null,
                    'outflow_cents' => $outflowCents ?: null,
                    'amount_cents' => $inflowCents > 0 ? $inflowCents : -$outflowCents,
                    'running_balance_cents' => $runningBalance,
                ];
            })
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
            ])
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $filters) {
                $query->where('wallet_id', $wallet->id)
                    ->whereDate('entry_date', '>=', $filters->startDate)
                    ->whereDate('entry_date', '<=', $filters->endDate);

                if ($filters->search !== '') {
                    $query->where('description', 'like', '%' . $filters->search . '%');
                }
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
