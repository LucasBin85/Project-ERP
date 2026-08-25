<?php

namespace App\Services\Financial;

use App\Models\BankReconciliation;
use Illuminate\Support\Collection;

class ReplaceBankReconciliationItems
{
    public function execute(BankReconciliation $reconciliation, array $statementItems, Collection $availableLines): void
    {
        $reconciliation->items()->delete();
        $reconciliation->statementItems()->delete();

        foreach ($statementItems as $statementItem) {
            $linkedLineId = $statementItem['journal_line_id'] ?? null;

            $reconciliation->statementItems()->create([
                'bank_statement_import_transaction_id' => $statementItem['bank_statement_import_transaction_id'] ?? null,
                'journal_line_id' => $linkedLineId,
                'transaction_date' => $statementItem['transaction_date'],
                'description' => $statementItem['description'],
                'amount_cents' => $statementItem['amount_cents'],
                'status' => $linkedLineId ? 'reconciled' : 'pending',
            ]);

            if ($linkedLineId) {
                $reconciliation->items()->create([
                    'journal_line_id' => $linkedLineId,
                    'amount_cents' => $availableLines->get($linkedLineId)['signed_amount_cents'],
                ]);
            }
        }
    }
}
