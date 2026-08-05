<?php

namespace App\Services\Financial;

use App\Models\CreditCardInstallmentPlan;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassifyCreditCardInstallmentPlan
{
    public function __construct(
        private readonly ValidateCreditCardPurchaseClassificationAccount $validateAccount,
        private readonly EnsureAccountingPeriodIsOpen $periodGuard,
    ) {}

    public function execute(Wallet $wallet, CreditCardInstallmentPlan $plan, int $accountId): CreditCardInstallmentPlan
    {
        return DB::transaction(function () use ($wallet, $plan, $accountId) {
            $plan = CreditCardInstallmentPlan::query()->where('wallet_id', $wallet->id)
                ->with('recognitionJournalEntry.lines')->lockForUpdate()->findOrFail($plan->id);
            $entry = $plan->recognitionJournalEntry;
            if (! $entry || $entry->status !== 'draft') {
                throw ValidationException::withMessages([
                    'installment_plan' => 'O parcelamento já foi contabilizado. Registre um ajuste ou reclassificação futura.',
                ]);
            }

            $this->periodGuard->handle($wallet, $entry->entry_date);
            $account = $this->validateAccount->execute($wallet, $accountId);
            $debit = $entry->lines->first(fn (JournalLine $line) => $line->type === 'debit');
            $credit = $entry->lines->first(fn (JournalLine $line) => $line->type === 'credit'
                && (int) $line->chart_of_account_id === (int) $plan->mainCreditCard()->value('liability_account_id'));
            if (! $debit || ! $credit || (int) $debit->amount_cents !== (int) $credit->amount_cents) {
                throw ValidationException::withMessages(['installment_plan' => 'O JE do plano não possui a estrutura contábil esperada.']);
            }

            $debit->update(['chart_of_account_id' => $account->id, 'memo' => 'Classificação do parcelamento do cartão']);
            $plan->update(['classification_account_id' => $account->id]);
            $plan->items()->whereNotNull('credit_card_purchase_id')->with('purchase')->get()
                ->each(fn ($item) => $item->purchase?->update(['expense_account_id' => $account->id]));

            return $plan->fresh(['classificationAccount', 'recognitionJournalEntry.lines']);
        });
    }
}
