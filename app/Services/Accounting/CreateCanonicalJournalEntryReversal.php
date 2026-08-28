<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateCanonicalJournalEntryReversal
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly PostJournalEntry $postJournalEntry,
        private readonly EnsureAccountingPeriodIsOpen $periodGuard,
    ) {}

    public function execute(Wallet $wallet, JournalEntry $original, int $amountCents, string $reversalDate, string $description): JournalEntry
    {
        abort_unless($original->wallet_id === $wallet->id, 404);
        if ($original->status !== 'posted') {
            throw ValidationException::withMessages(['settlement' => 'O lançamento original precisa estar contabilizado.']);
        }
        try {
            $date = CarbonImmutable::parse($reversalDate);
        } catch (Throwable) {
            throw ValidationException::withMessages(['reversal_date' => 'Informe uma data contábil de estorno válida.']);
        }
        if ($date->startOfDay()->lt($original->entry_date->startOfDay())) {
            throw ValidationException::withMessages(['reversal_date' => 'A data do estorno não pode ser anterior ao lançamento original.']);
        }
        $this->periodGuard->handle($wallet, $date);
        $lines = $original->lines()->orderBy('id')->get();
        if ($lines->count() !== 2 || $lines->pluck('type')->sort()->values()->all() !== ['credit', 'debit']
            || $lines->pluck('amount_cents')->unique()->count() !== 1 || $amountCents <= 0) {
            throw ValidationException::withMessages(['settlement' => 'O lançamento não possui a estrutura contábil canônica esperada.']);
        }
        $reversal = $this->createJournalEntry->execute([
            'wallet_id' => $wallet->id, 'entry_date' => $date->toDateString(), 'description' => $description,
            'lines' => $lines->map(fn ($line) => [
                'chart_of_account_id' => $line->chart_of_account_id,
                'type' => $line->type === 'debit' ? 'credit' : 'debit',
                'amount_cents' => $amountCents,
            ])->all(),
        ]);
        $reversal->update(['reversal_of_journal_entry_id' => $original->id]);

        return $this->postJournalEntry->handle($reversal);
    }
}
