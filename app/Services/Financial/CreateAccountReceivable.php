<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountReceivableDTO;
use App\Models\AccountReceivable;
use App\Models\ChartOfAccount;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class CreateAccountReceivable
{
    public function execute(Wallet $wallet, AccountReceivableDTO $dto): AccountReceivable
    {
        $revenueAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'receita')
            ->where('allows_posting', true)
            ->find($dto->revenueAccountId);

        if (! $revenueAccount) {
            throw ValidationException::withMessages([
                'revenue_account_id' => 'Conta de receita inválida para contas a receber.',
            ]);
        }

        return AccountReceivable::query()->create([
            'wallet_id' => $wallet->id,
            'revenue_account_id' => $revenueAccount->id,
            'customer_name' => $dto->customerName,
            'description' => $dto->description,
            'due_date' => $dto->dueDate,
            'amount_cents' => $dto->amountCents,
            'status' => 'pending',
            'notes' => $dto->notes,
        ])->fresh('revenueAccount');
    }
}
