<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Models\ChartOfAccount;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCreditCardTransaction
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly PostJournalEntry $postJournalEntry,
        private readonly ResolveCreditCardInvoice $resolveCreditCardInvoice,
    ) {
    }

    public function execute(Wallet $wallet, CreditCardTransactionDTO $dto): CreditCardTransaction
    {
        return DB::transaction(function () use ($wallet, $dto) {
            $creditCard = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->with(['liabilityAccount', 'parentCard'])
                ->findOrFail($dto->creditCardId);

            $mainCard = $this->resolveCreditCardInvoice->mainCard($creditCard);
            $invoice = $this->resolveCreditCardInvoice->forPurchaseDate($wallet, $creditCard, $dto->purchaseDate);

            $expenseAccount = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', 'despesa')
                ->where('allows_posting', true)
                ->find($dto->expenseAccountId);

            if (! $expenseAccount) {
                throw ValidationException::withMessages([
                    'expense_account_id' => 'Conta de despesa inválida para compra no cartão.',
                ]);
            }

            $journalEntry = $this->createJournalEntry->execute([
                'wallet_id' => $wallet->id,
                'entry_date' => $dto->purchaseDate,
                'description' => 'Compra no cartão: ' . $dto->description,
                'lines' => [
                    [
                        'chart_of_account_id' => $expenseAccount->id,
                        'type' => 'debit',
                        'amount_cents' => $dto->amountCents,
                    ],
                    [
                        'chart_of_account_id' => $mainCard->liability_account_id,
                        'type' => 'credit',
                        'amount_cents' => $dto->amountCents,
                    ],
                ],
            ]);

            $journalEntry = $this->postJournalEntry->handle($journalEntry);

            $transaction = CreditCardTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'credit_card_id' => $creditCard->id,
                'credit_card_invoice_id' => $invoice->id,
                'expense_account_id' => $expenseAccount->id,
                'journal_entry_id' => $journalEntry->id,
                'purchase_date' => $dto->purchaseDate,
                'merchant_name' => $dto->merchantName,
                'description' => $dto->description,
                'amount_cents' => $dto->amountCents,
                'installments_total' => $dto->installmentsTotal,
                'installment_number' => $dto->installmentNumber,
                'status' => 'posted',
                'notes' => $dto->notes,
            ]);

            $this->resolveCreditCardInvoice->refreshTotals($invoice);

            return $transaction->fresh([
                'creditCard',
                'creditCardInvoice',
                'expenseAccount',
                'journalEntry.lines.chartOfAccount',
            ]);
        });
    }
}
