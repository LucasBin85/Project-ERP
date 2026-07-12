<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Models\Wallet;

class FindMatchingOfxJournalLine
{
    public function find(
        Wallet $wallet,
        BankAccount $bankAccount,
        string $entryDate,
        int $amountCents,
        string $direction,
    ): ?JournalLine {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id) {
            return null;
        }

        $lineType = match ($direction) {
            'in' => 'debit',
            'out' => 'credit',
            default => null,
        };

        if ($lineType === null || $amountCents <= 0) {
            return null;
        }

        $candidates = JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.wallet_id', $wallet->id)
            ->where('journal_entries.source', 'manual')
            ->whereDate('journal_entries.entry_date', $entryDate)
            ->where('journal_lines.chart_of_account_id', $bankAccount->chart_of_account_id)
            ->where('journal_lines.type', $lineType)
            ->where('journal_lines.amount_cents', $amountCents)
            ->whereNotExists(function ($query) use ($wallet, $bankAccount) {
                $query->selectRaw('1')
                    ->from('bank_statement_import_transactions')
                    ->where('bank_statement_import_transactions.status', 'imported')
                    ->where('bank_statement_import_transactions.wallet_id', $wallet->id)
                    ->where('bank_statement_import_transactions.bank_account_id', $bankAccount->id)
                    ->where(function ($query) {
                        $query->whereColumn(
                            'bank_statement_import_transactions.journal_line_id',
                            'journal_lines.id',
                        )->orWhere(function ($query) {
                            $query->whereNull('bank_statement_import_transactions.journal_line_id')
                                ->whereColumn(
                                    'bank_statement_import_transactions.journal_entry_id',
                                    'journal_lines.journal_entry_id',
                                );
                        });
                    });
            })
            ->with('journalEntry')
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
