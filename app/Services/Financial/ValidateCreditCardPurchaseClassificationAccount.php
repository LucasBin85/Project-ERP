<?php

namespace App\Services\Financial;

use App\Models\ChartOfAccount;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class ValidateCreditCardPurchaseClassificationAccount
{
    public function execute(Wallet $wallet, int $accountId): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->whereIn('type', ['despesa', 'ativo'])
            ->where('allows_posting', true)
            ->whereDoesntHave('children')
            ->whereNotIn('id', fn ($query) => $query->select('chart_of_account_id')->from('bank_accounts'))
            ->whereNotIn('id', fn ($query) => $query->select('liability_account_id')->from('credit_cards'))
            ->find($accountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'chart_of_account_id' => 'Selecione uma conta analítica de despesa, ativo ou investimento da wallet ativa.',
            ]);
        }

        return $account;
    }
}
