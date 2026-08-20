<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Wallet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmAndLinkRecurringPayableFromBankStatement
{
    public function __construct(private readonly ConfirmRecurringFinancialExpectation $confirm, private readonly LinkAccountPayableFromBankStatement $link) {}

    public function execute(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry, RecurringFinancialExpectation $expectation, CarbonInterface $period, CarbonInterface $dueDate, ?string $notes = null): RecurringFinancialOccurrence
    {
        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $expectation, $period, $dueDate, $notes) {
            $entry = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($expectation->wallet_id !== $wallet->id || $expectation->type !== 'payable') {
                throw ValidationException::withMessages(['expectation' => 'Recorrência a pagar inválida para esta carteira.']);
            }
            $amount = (int) $entry->lines()->where('chart_of_account_id', $bankAccount->chart_of_account_id)->value('amount_cents');
            $occurrence = $this->confirm->execute($wallet, $expectation, $period, $amount, $dueDate, $notes);
            $this->link->execute($wallet, $bankAccount, $entry, $occurrence->accountPayable()->firstOrFail());

            return $occurrence->fresh(['accountPayable.paymentJournalEntry', 'accountPayable.provisionJournalEntry']);
        });
    }
}
