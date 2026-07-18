<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountReceivableDTO;
use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class CreateAndLinkAccountReceivableFromBankStatement
{
    public function __construct(private readonly CreateAccountReceivable $create, private readonly LinkAccountReceivableFromBankStatement $link) {}
    public function execute(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry, AccountReceivableDTO $dto): AccountReceivable
    {
        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $dto) {
            $entry = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $receivable = $this->create->execute($wallet, $dto);
            return $this->link->execute($wallet, $bankAccount, $entry, $receivable);
        });
    }
}
