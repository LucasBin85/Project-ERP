<?php

namespace App\Services\Financial;

use App\Models\BankReconciliation;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscardBankReconciliationDraft
{
    public function execute(Wallet $wallet, BankReconciliation $reconciliation): void
    {
        DB::transaction(function () use ($wallet, $reconciliation) {
            $locked = BankReconciliation::query()
                ->where('wallet_id', $wallet->id)
                ->lockForUpdate()
                ->findOrFail($reconciliation->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Uma conciliação concluída não pode ser descartada.']);
            }

            $locked->delete();
        });
    }
}
