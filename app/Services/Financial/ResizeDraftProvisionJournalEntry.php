<?php

namespace App\Services\Financial;

use App\Models\JournalEntry;
use RuntimeException;

class ResizeDraftProvisionJournalEntry
{
    public function execute(JournalEntry $entry, int $amountCents): void
    {
        if ($entry->status !== 'draft') {
            throw new RuntimeException('Somente uma provisão draft pode ser redimensionada.');
        }

        if ($amountCents === 0) {
            $entry->delete();

            return;
        }

        $entry->lines()->update(['amount_cents' => $amountCents]);
        $entry->recalcBalance();
        $entry->save();
    }
}
