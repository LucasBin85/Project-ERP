<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CreditCardPaymentDTO;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardPayment;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayCreditCardInvoice
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly ResolveCreditCardInvoice $resolveCreditCardInvoice,
    ) {}

    public function execute(Wallet $wallet, CreditCardPaymentDTO $dto): CreditCardPayment
    {
        return DB::transaction(function () use ($wallet, $dto) {
            $creditCard = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->with('liabilityAccount')
                ->findOrFail($dto->creditCardId);

            if ($creditCard->parent_card_id) {
                throw ValidationException::withMessages([
                    'credit_card_id' => 'O pagamento deve ser registrado na fatura do cartão principal.',
                ]);
            }

            $invoice = CreditCardInvoice::query()
                ->where('wallet_id', $wallet->id)
                ->where('credit_card_id', $creditCard->id)
                ->findOrFail($dto->creditCardInvoiceId);

            if (in_array($invoice->status, ['paid', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'credit_card_invoice_id' => 'Esta fatura não aceita novos pagamentos.',
                ]);
            }

            if ($dto->amountCents > $invoice->balance_cents) {
                throw ValidationException::withMessages([
                    'amount_cents' => 'O pagamento não pode ser maior que o saldo em aberto da fatura.',
                ]);
            }

            $bankAccount = BankAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->with('chartOfAccount')
                ->find($dto->bankAccountId);

            if (! $bankAccount || ! $bankAccount->chartOfAccount?->allows_posting) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Conta bancária inválida para pagamento da fatura.',
                ]);
            }

            $description = $dto->description ?: sprintf(
                'Pagamento fatura %02d/%d: %s',
                $invoice->reference_month,
                $invoice->reference_year,
                $creditCard->name,
            );

            $journalEntry = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id,
                'entry_date' => $dto->paymentDate,
                'description' => $description,
                'lines' => [
                    [
                        'chart_of_account_id' => $creditCard->liability_account_id,
                        'type' => 'debit',
                        'amount_cents' => $dto->amountCents,
                    ],
                    [
                        'chart_of_account_id' => $bankAccount->chart_of_account_id,
                        'type' => 'credit',
                        'amount_cents' => $dto->amountCents,
                    ],
                ],
            ]);

            $payment = CreditCardPayment::query()->create([
                'wallet_id' => $wallet->id,
                'credit_card_id' => $creditCard->id,
                'credit_card_invoice_id' => $invoice->id,
                'bank_account_id' => $bankAccount->id,
                'journal_entry_id' => $journalEntry->id,
                'payment_date' => $dto->paymentDate,
                'amount_cents' => $dto->amountCents,
                'description' => $description,
                'status' => 'draft',
                'notes' => $dto->notes,
            ]);

            $this->resolveCreditCardInvoice->refreshTotals($invoice);

            return $payment->fresh([
                'creditCard',
                'creditCardInvoice',
                'bankAccount',
                'journalEntry.lines.chartOfAccount',
            ]);
        });
    }
}
