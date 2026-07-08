<?php

namespace App\Services\Financial;

use App\DTOs\Financial\AccountPayableDTO;
use App\Models\AccountPayable;
use App\Models\ChartOfAccount;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class CreateAccountPayable
{
    public function execute(Wallet $wallet, AccountPayableDTO $dto): AccountPayable
    {
        $expenseAccount = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', 'despesa')
            ->where('allows_posting', true)
            ->find($dto->expenseAccountId);

        if (! $expenseAccount) {
            throw ValidationException::withMessages([
                'expense_account_id' => 'Conta de despesa inválida para contas a pagar.',
            ]);
        }

        return AccountPayable::query()->create([
            'wallet_id' => $wallet->id,
            'expense_account_id' => $expenseAccount->id,
            'payee_name' => $dto->payeeName,
            'description' => $dto->description,
            'due_date' => $dto->dueDate,
            'amount_cents' => $dto->amountCents,
            'status' => 'pending',
            'notes' => $dto->notes,
        ])->fresh('expenseAccount');
    }
}
