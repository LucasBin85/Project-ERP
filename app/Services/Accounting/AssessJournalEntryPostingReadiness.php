<?php

namespace App\Services\Accounting;

use App\DTOs\Accounting\JournalEntryPostingReadinessDTO;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;

class AssessJournalEntryPostingReadiness
{
    public function handle(
        Wallet $wallet,
        JournalEntry $entry,
    ): JournalEntryPostingReadinessDTO {
        if ((int) $entry->wallet_id !== (int) $wallet->id) {
            return JournalEntryPostingReadinessDTO::pending(
                'O lançamento não pertence à wallet ativa.',
            );
        }

        if ($entry->status !== 'draft') {
            return JournalEntryPostingReadinessDTO::pending(
                'O lançamento não está mais em rascunho.',
            );
        }

        if (trim((string) $entry->source) === '') {
            return JournalEntryPostingReadinessDTO::pending(
                'O lançamento não possui origem identificada.',
            );
        }

        $entry->loadMissing('lines.chartOfAccount.children');
        $lines = $entry->lines;

        if ($lines->count() < 2) {
            return JournalEntryPostingReadinessDTO::pending(
                'O lançamento precisa possuir ao menos duas linhas.',
            );
        }

        if ($wallet->suspense_account_id && $lines->contains(
            fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id,
        )) {
            return JournalEntryPostingReadinessDTO::pending(
                'O lançamento ainda possui valor em "A classificar".',
            );
        }

        $invalidLine = $lines->first(function (JournalLine $line) use ($wallet) {
            $account = $line->chartOfAccount;

            return ! in_array($line->type, ['debit', 'credit'], true)
                || (int) $line->amount_cents <= 0
                || ! $account
                || (int) $account->wallet_id !== (int) $wallet->id
                || ! $account->isPostingAllowed()
                || $account->children->isNotEmpty();
        });

        if ($invalidLine) {
            return JournalEntryPostingReadinessDTO::pending(
                'Todas as linhas devem usar contas analíticas e lançáveis da wallet ativa.',
            );
        }

        $debits = (int) $lines->where('type', 'debit')->sum('amount_cents');
        $credits = (int) $lines->where('type', 'credit')->sum('amount_cents');

        if ($debits <= 0 || $credits <= 0 || $debits !== $credits) {
            return JournalEntryPostingReadinessDTO::pending(
                'O lançamento não está balanceado.',
            );
        }

        return JournalEntryPostingReadinessDTO::ready();
    }
}
