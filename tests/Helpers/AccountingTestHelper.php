<?php

namespace Tests\Helpers;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;

class AccountingTestHelper
{
    public static function account(
        Wallet $wallet,
        string $code,
        string $name,
        string $type,
        string $normalBalance
    ): ChartOfAccount {
        return ChartOfAccount::query()->updateOrCreate(
            [
                'wallet_id' => $wallet->id,
                'code' => $code,
            ],
            [
                'name' => $name,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'allows_posting' => true,
            ],
        );
    }

    public static function createPostedEntry(
        Wallet $wallet,
        string $date,
        array $lines
    ): JournalEntry {
        $debit = collect($lines)->where(1, 'debit')->sum(2);
        $credit = collect($lines)->where(1, 'credit')->sum(2);

        $entry = JournalEntry::query()->create([
            'wallet_id' => $wallet->id,
            'source' => 'manual',
            'entry_date' => $date,
            'description' => 'Teste',
            'status' => 'posted',
            'posted_at' => now(),
            'is_balanced' => $debit === $credit,
            'balance_diff_cents' => $debit - $credit,
        ]);

        foreach ($lines as [$account, $type, $amount]) {
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'chart_of_account_id' => $account->id,
                'type' => $type,
                'amount_cents' => $amount,
            ]);
        }

        return $entry;
    }
}
