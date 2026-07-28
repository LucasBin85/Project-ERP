<?php

namespace App\Services\Financial;

use App\Models\CreditCardTransaction;
use App\Models\Wallet;

class SuggestCreditCardPurchaseClassification
{
    public function __construct(
        private readonly NormalizeBankStatementDescription $normalize,
        private readonly ValidateCreditCardPurchaseClassificationAccount $validateAccount,
    ) {}

    public function execute(Wallet $wallet, CreditCardTransaction $transaction): ?array
    {
        $description = $this->normalize->execute($transaction->merchant_name ?: $transaction->description);
        if (mb_strlen($description) < 4) {
            return null;
        }

        $history = CreditCardTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->whereKeyNot($transaction->id)
            ->whereNotNull('expense_account_id')
            ->where('expense_account_id', '!=', $wallet->suspense_account_id)
            ->where(function ($query) use ($transaction) {
                $query->whereDate('purchase_date', '<', $transaction->purchase_date)
                    ->orWhere(fn ($sameDate) => $sameDate->whereDate('purchase_date', $transaction->purchase_date)
                        ->where('id', '<', $transaction->id));
            })
            ->latest('purchase_date')
            ->latest('id')
            ->limit(500)
            ->get(['id', 'merchant_name', 'description', 'expense_account_id'])
            ->filter(fn (CreditCardTransaction $item) => $this->normalize->execute($item->merchant_name ?: $item->description) === $description)
            ->filter(function (CreditCardTransaction $item) use ($wallet) {
                try {
                    $this->validateAccount->execute($wallet, (int) $item->expense_account_id);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->values();

        if ($history->isEmpty()) {
            return null;
        }

        $groups = $history->groupBy('expense_account_id');
        if ($groups->count() > 1) {
            return [
                'status' => 'ambiguous',
                'source' => 'history',
                'confidence' => 'low',
                'history_count' => $history->count(),
                'can_apply' => false,
                'can_bulk_apply' => false,
            ];
        }

        $account = $this->validateAccount->execute($wallet, (int) $history->first()->expense_account_id);
        $count = $history->count();

        return [
            'status' => 'suggested',
            'source' => 'history',
            'confidence' => $count >= 2 ? 'high' : 'medium',
            'history_count' => $count,
            'chart_of_account_id' => $account->id,
            'target_label' => trim($account->code.' · '.$account->name, ' ·'),
            'can_apply' => true,
            'can_bulk_apply' => $count >= 2,
            'suggestion_key' => 'history:'.hash('sha256', $account->id.'|'.$description),
        ];
    }
}
