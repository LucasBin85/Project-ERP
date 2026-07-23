<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardPayment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkCreditCardInvoicePaymentFromBankStatement
{
    public function __construct(
        private readonly EnsureAccountingPeriodIsOpen $periodGuard,
        private readonly UpdateCreditCardInvoiceStatus $invoiceStatus,
    ) {}

    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
        int $invoiceId,
    ): CreditCardPayment {
        $this->periodGuard->handle($wallet, $entry->entry_date);

        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $invoiceId) {
            $entry = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $invoice = CreditCardInvoice::query()
                ->whereKey($invoiceId)
                ->where('wallet_id', $wallet->id)
                ->whereIn('status', ['open', 'closed', 'partial', 'overdue'])
                ->with('creditCard.liabilityAccount')
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $bankAccount->wallet_id !== (int) $wallet->id
                || (int) $entry->wallet_id !== (int) $wallet->id
                || $entry->status !== 'draft'
                || ! in_array($entry->source, OfxOperationTypePolicy::STATEMENT_IMPORT_SOURCES, true)) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'O pagamento deve usar um movimento importado, em rascunho e da wallet ativa.',
                ]);
            }

            $lines = $entry->lines()->lockForUpdate()->get();
            $bankLine = $lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
            $classificationLine = $lines->first(fn (JournalLine $line) => $line->id !== $bankLine?->id);

            if (! $bankLine || $bankLine->type !== 'credit' || $lines->count() !== 2 || ! $classificationLine) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'Selecione uma saída bancária com uma única linha de classificação.',
                ]);
            }

            $amount = (int) $bankLine->amount_cents;
            if ($amount > $invoice->balance_cents) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'O pagamento não pode ser maior que o saldo em aberto da fatura.',
                ]);
            }

            if (CreditCardPayment::query()->where('journal_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['invoice_id' => 'Este movimento já está vinculado a uma fatura.']);
            }

            $classificationLine->update([
                'chart_of_account_id' => $invoice->creditCard->liability_account_id,
                'type' => 'debit',
                'amount_cents' => $amount,
                'memo' => 'Pagamento da fatura',
            ]);
            $entry->update(['description' => 'Pagamento da fatura: '.$invoice->creditCard->name]);
            $entry->recalcBalance();
            $entry->save();

            $payment = CreditCardPayment::query()->create([
                'wallet_id' => $wallet->id,
                'credit_card_id' => $invoice->credit_card_id,
                'credit_card_invoice_id' => $invoice->id,
                'bank_account_id' => $bankAccount->id,
                'journal_entry_id' => $entry->id,
                'payment_date' => $entry->entry_date,
                'amount_cents' => $amount,
                'description' => $entry->description,
                'status' => 'draft',
            ]);

            $this->invoiceStatus->execute($invoice);

            return $payment->fresh(['creditCardInvoice', 'journalEntry.lines']);
        });
    }
}
