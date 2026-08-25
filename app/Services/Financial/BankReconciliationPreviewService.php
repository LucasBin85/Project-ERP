<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BankReconciliationPreviewService
{
    public function build(Wallet $wallet, BankAccount $bankAccount, string $periodStart, string $periodEnd): array
    {
        $openingBalanceCents = $this->openingBalance($wallet, $bankAccount, $periodStart);
        $lines = $this->periodLines($wallet, $bankAccount, $periodStart, $periodEnd);

        $bookBalanceCents = $openingBalanceCents + $lines->sum('signed_amount_cents');

        return [
            'opening_balance_cents' => $openingBalanceCents,
            'book_balance_cents' => $bookBalanceCents,
            'lines' => $lines->values(),
        ];
    }

    public function buildForStatement(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $periodStart,
        string $periodEnd,
        int $statementBalanceCents,
        array $statementItems,
        ?int $ignoredReconciliationId = null,
    ): array {
        $preview = $this->build($wallet, $bankAccount, $periodStart, $periodEnd);
        $availableLines = collect($preview['lines'])->keyBy('id');
        $items = collect($statementItems);
        $linkedLineIds = $items->pluck('journal_line_id')->filter()->map(fn ($id) => (int) $id)->values();

        if ($linkedLineIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'statement_items' => 'Um mesmo lançamento do sistema não pode ser vinculado a mais de um item do extrato.',
            ]);
        }

        $invalidIds = $linkedLineIds->unique()->reject(fn (int $id) => $availableLines->has($id));

        if ($invalidIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'statement_items' => 'Uma ou mais movimentações vinculadas não pertencem à conta, período ou carteira informados.',
            ]);
        }

        $statementMovementCents = (int) $items->sum('amount_cents');
        $calculatedStatementBalanceCents = (int) $preview['opening_balance_cents'] + $statementMovementCents;
        $reconciledMovementCents = (int) $linkedLineIds->unique()
            ->map(fn (int $id) => $availableLines->get($id))
            ->sum('signed_amount_cents');
        $reconciledBalanceCents = (int) $preview['opening_balance_cents'] + $reconciledMovementCents;
        $differenceCents = $reconciledBalanceCents - $statementBalanceCents;
        $pendingCount = $items->filter(fn (array $item) => empty($item['journal_line_id']))->count();
        $overlap = BankReconciliation::query()
            ->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $bankAccount->id)
            ->whereDate('period_start', '<=', $periodEnd)
            ->whereDate('period_end', '>=', $periodStart)
            ->when($ignoredReconciliationId !== null, fn ($query) => $query->whereKeyNot($ignoredReconciliationId))
            ->orderBy('id')
            ->first(['id']);

        return [
            'bank_account' => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->name,
            ],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'opening_balance_cents' => (int) $preview['opening_balance_cents'],
            'statement_balance_cents' => $statementBalanceCents,
            'calculated_statement_balance_cents' => $calculatedStatementBalanceCents,
            'statement_items_difference_cents' => $calculatedStatementBalanceCents - $statementBalanceCents,
            'book_balance_cents' => (int) $preview['book_balance_cents'],
            'reconciled_balance_cents' => $reconciledBalanceCents,
            'difference_cents' => $differenceCents,
            'statement_items' => $items->map(fn (array $item) => [
                ...$item,
                'status' => empty($item['journal_line_id']) ? 'pending' : 'reconciled',
            ])->values(),
            'pending_count' => $pendingCount,
            'status' => $differenceCents === 0 && $pendingCount === 0 ? 'completed' : 'draft',
            'lines' => $preview['lines'],
            'has_existing_overlap' => $overlap !== null,
            'existing_reconciliation_id' => $overlap?->id,
        ];
    }

    public function openingBalance(Wallet $wallet, BankAccount $bankAccount, string $periodStart): int
    {
        $lines = JournalLine::query()
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $periodStart) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '<', $periodStart);
            })
            ->get(['type', 'amount_cents']);

        return $lines->reduce(fn (int $balance, JournalLine $line) => $balance + $this->signedAmount($line), 0);
    }

    public function periodLines(Wallet $wallet, BankAccount $bankAccount, string $periodStart, string $periodEnd): Collection
    {
        return JournalLine::query()
            ->with([
                'journalEntry:id,wallet_id,entry_date,description,status',
            ])
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->whereHas('journalEntry', function ($query) use ($wallet, $periodStart, $periodEnd) {
                $query->where('wallet_id', $wallet->id)
                    ->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $periodStart)
                    ->whereDate('entry_date', '<=', $periodEnd);
            })
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get()
            ->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'date' => $line->journalEntry?->entry_date,
                'journal_entry_id' => $line->journalEntry?->id,
                'description' => $line->memo ?: $line->journalEntry?->description,
                'type' => $line->type,
                'amount_cents' => (int) $line->amount_cents,
                'signed_amount_cents' => $this->signedAmount($line),
                'status' => $line->journalEntry?->status,
            ]);
    }

    private function signedAmount(JournalLine $line): int
    {
        return $line->type === 'debit'
            ? (int) $line->amount_cents
            : -1 * (int) $line->amount_cents;
    }
}
