<?php

namespace App\Services\Financial;

use App\Models\CreditCardTransaction;
use App\Models\Wallet;

class BulkApplyCreditCardPurchaseSuggestions
{
    public function __construct(
        private readonly SuggestCreditCardPurchaseClassification $suggest,
        private readonly ClassifyCreditCardPurchase $classify,
    ) {}

    public function execute(Wallet $wallet, array $transactionIds): array
    {
        $result = ['applied' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (array_values(array_unique(array_map('intval', $transactionIds))) as $id) {
            try {
                $transaction = CreditCardTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->with('journalEntry:id,status')
                    ->find($id);
                if (! $transaction || $transaction->journalEntry?->status !== 'draft') {
                    $result['skipped']++;

                    continue;
                }

                $suggestion = $this->suggest->execute($wallet, $transaction);
                if (! ($suggestion['can_bulk_apply'] ?? false) || ($suggestion['confidence'] ?? null) !== 'high') {
                    $result['skipped']++;

                    continue;
                }

                $this->classify->execute($wallet, $transaction, (int) $suggestion['chart_of_account_id']);
                $result['applied']++;
            } catch (\Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        return $result;
    }
}
