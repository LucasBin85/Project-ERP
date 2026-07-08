<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Collection;

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
