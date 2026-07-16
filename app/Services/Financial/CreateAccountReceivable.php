<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountReceivableDTO;
use App\Models\AccountReceivable;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAccountReceivable
{
    public function __construct(private readonly CreateJournalEntry $createJournalEntry) {}

    public function execute(Wallet $wallet, AccountReceivableDTO $dto): AccountReceivable
    {
        $customer = $dto->customerId ? Customer::query()->validForReceivables($wallet->id)->find($dto->customerId) : null;
        if ($dto->customerId && ! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Cliente ativo inválido.']);
        }
        $revenueId = $customer?->default_revenue_account_id ?? $dto->revenueAccountId;
        $receivableId = $customer?->receivable_account_id ?? $dto->receivableAccountId;
        $revenueAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'receita')
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->find($revenueId);

        if (! $revenueAccount) {
            throw ValidationException::withMessages([
                'revenue_account_id' => 'Conta de receita inválida para contas a receber.',
            ]);
        }

        $receivableAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)->where('type', 'ativo')
            ->where('financial_group', 'accounts_receivable')->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->when($receivableId, fn ($query) => $query->whereKey($receivableId))
            ->orderBy('code')->first();
        if (! $receivableAccount) {
            throw ValidationException::withMessages(['receivable_account_id' => 'Conta de controle do cliente inválida.']);
        }

        return DB::transaction(function () use ($wallet, $dto, $revenueAccount, $receivableAccount, $customer) {
            $title = AccountReceivable::query()->create([
                'wallet_id' => $wallet->id, 'receivable_account_id' => $receivableAccount->id,
                'customer_id' => $customer?->id,
                'revenue_account_id' => $revenueAccount->id, 'customer_name' => $customer?->name ?? $dto->customerName,
                'description' => $dto->description, 'due_date' => $dto->dueDate,
                'amount_cents' => $dto->amountCents, 'status' => 'pending', 'notes' => $dto->notes,
            ]);
            $provision = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id, 'entry_date' => $dto->dueDate,
                'description' => 'Provisão: '.$dto->description,
                'lines' => [
                    ['chart_of_account_id' => $receivableAccount->id, 'type' => 'debit', 'amount_cents' => $dto->amountCents],
                    ['chart_of_account_id' => $revenueAccount->id, 'type' => 'credit', 'amount_cents' => $dto->amountCents],
                ],
            ]);
            $title->update(['provision_journal_entry_id' => $provision->id]);

            return $title->fresh(['revenueAccount', 'receivableAccount', 'provisionJournalEntry.lines.chartOfAccount']);
        });
    }
}
