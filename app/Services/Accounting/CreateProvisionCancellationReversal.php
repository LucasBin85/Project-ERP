<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class CreateProvisionCancellationReversal
{
    public function __construct(
        private readonly CreateCanonicalJournalEntryReversal $createReversal,
    ) {}

    public function execute(Wallet $wallet, JournalEntry $original, int $amountCents, string $reversalDate, string $description): JournalEntry
    {
        abort_unless($original->wallet_id === $wallet->id, 404);
        if ($original->status !== 'posted') {
            throw ValidationException::withMessages(['provision' => 'A provisão precisa estar contabilizada para ser revertida.']);
        }

        $lines = $original->lines()->orderBy('id')->get();
        if ($lines->count() !== 2 || $lines->pluck('type')->sort()->values()->all() !== ['credit', 'debit']
            || $lines->pluck('amount_cents')->unique()->count() !== 1) {
            throw ValidationException::withMessages(['provision' => 'A provisão não possui a estrutura contábil canônica esperada.']);
        }

        $originalAmount = (int) $lines->first()->amount_cents;
        $alreadyReversed = (int) $original->reversals()->with('lines')->get()
            ->sum(fn (JournalEntry $entry) => $entry->lines->where('type', 'debit')->sum('amount_cents'));
        if ($amountCents <= 0 || $alreadyReversed + $amountCents > $originalAmount) {
            throw ValidationException::withMessages(['provision' => 'O valor acumulado dos estornos excede a provisão original.']);
        }

        return $this->createReversal->execute($wallet, $original, $amountCents, $reversalDate, $description);
    }
}
