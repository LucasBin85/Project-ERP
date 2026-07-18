<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountPayableDTO;
use App\Models\AccountPayable;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class CreateAndLinkAccountPayableFromBankStatement
{
    public function __construct(private readonly CreateAccountPayable $create, private readonly LinkAccountPayableFromBankStatement $link) {}
    public function execute(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry, AccountPayableDTO $dto): AccountPayable
    {
        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $dto) {
            $entry = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $payable = $this->create->execute($wallet, $dto);
            return $this->link->execute($wallet, $bankAccount, $entry, $payable);
        });
    }
}
