<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;

class FindBankStatementRecurringReceivableCandidates
{
    public function __construct(private readonly FindBankStatementRecurringCandidates $candidates) {}

    public function execute(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry): array
    {
        return $this->candidates->execute($wallet, $bankAccount, $entry, 'receivable');
    }
}
