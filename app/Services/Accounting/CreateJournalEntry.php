<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateJournalEntry
{
    public function execute(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data) {

            $debitTotal = 0;
            $creditTotal = 0;

            foreach ($data['lines'] as $line) {

                $account = ChartOfAccount::query()
                    ->whereKey($line['chart_of_account_id'])
                    ->where('wallet_id', $data['wallet_id'])
                    ->where('allows_posting', true)
                    ->first();

                if (! $account) {
                    throw ValidationException::withMessages([
                        'lines' => 'Conta inválida para lançamento.',
                    ]);
                }

                $amount = (int) $line['amount_cents'];

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'Valores devem ser maiores que zero.',
                    ]);
                }

                if ($line['type'] === 'debit') {
                    $debitTotal += $amount;
                }

                if ($line['type'] === 'credit') {
                    $creditTotal += $amount;
                }
            }

            $difference = $debitTotal - $creditTotal;

            $journalEntry = JournalEntry::create([
                'wallet_id' => $data['wallet_id'],
                'entry_date' => $data['entry_date'],
                'description' => $data['description'],
                'source' => 'manual',
                'status' => 'draft',
                'is_balanced' => $difference === 0,
                'balance_diff_cents' => $difference,
            ]);

            foreach ($data['lines'] as $line) {

                $journalEntry->lines()->create([
                    'chart_of_account_id' => $line['chart_of_account_id'],
                    'type' => $line['type'],
                    'amount_cents' => (int) $line['amount_cents'],
                ]);
            }

            return $journalEntry->fresh('lines.chartOfAccount');
        });
    }
}