<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Models\ChartOfAccount;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCreditCardTransaction
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly ResolveCreditCardInvoice $resolveCreditCardInvoice,
        private readonly CreateCreditCardInstallments $installments,
        private readonly ResolveCreditCardInstallmentPlan $installmentPlans,
    ) {}

    public function execute(Wallet $wallet, CreditCardTransactionDTO $dto): CreditCardTransaction
    {
        return DB::transaction(function () use ($wallet, $dto) {
            $creditCard = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->with(['liabilityAccount', 'parentCard'])
                ->findOrFail($dto->creditCardId);

            $mainCard = $this->resolveCreditCardInvoice->mainCard($creditCard);

            $expenseAccount = ChartOfAccount::query()
                ->where('wallet_id', $wallet->id)
                ->whereIn('type', ['despesa', 'ativo'])
                ->where('allows_posting', true)
                ->whereDoesntHave('children')
                ->whereNotIn('id', fn ($query) => $query->select('chart_of_account_id')->from('bank_accounts'))
                ->find($dto->expenseAccountId);

            if (! $expenseAccount) {
                throw ValidationException::withMessages([
                    'expense_account_id' => 'Conta de despesa inválida para compra no cartão.',
                ]);
            }

            $installmentsTotal = max(1, $dto->installmentsTotal);
            $amounts = $this->installments->split($dto->amountCents, $installmentsTotal);
            $purchaseDate = CarbonImmutable::parse($dto->purchaseDate)->startOfDay();
            if ($installmentsTotal > 1) {
                $invoice = $this->resolveCreditCardInvoice->forPurchaseDate($wallet, $creditCard, $purchaseDate->toDateString());
                $transaction = CreditCardTransaction::query()->create([
                    'wallet_id' => $wallet->id,
                    'credit_card_id' => $creditCard->id,
                    'credit_card_invoice_id' => $invoice->id,
                    'expense_account_id' => $expenseAccount->id,
                    'journal_entry_id' => null,
                    'source' => 'manual',
                    'purchase_date' => $purchaseDate->toDateString(),
                    'merchant_name' => $dto->merchantName,
                    'description' => $dto->description,
                    'amount_cents' => $amounts[0],
                    'installments_total' => $installmentsTotal,
                    'installment_number' => 1,
                    'status' => 'draft',
                    'notes' => $dto->notes,
                ]);
                $detected = app(DetectCreditCardInstallment::class)->normalize($dto->description);
                $this->installmentPlans->create(
                    $wallet, $mainCard, $creditCard, $invoice, $transaction,
                    [
                        'installment_number' => 1,
                        'installments_total' => $installmentsTotal,
                        'amount_cents' => $amounts[0],
                        'description_base' => $dto->description,
                        'normalized_description' => $detected,
                    ],
                    [
                        'description_base' => $dto->description,
                        'recognized_total_cents' => $dto->amountCents,
                        'original_total_cents' => $dto->amountCents,
                        'classification_account_id' => $expenseAccount->id,
                        'recognition_date' => $purchaseDate->toDateString(),
                        'installments' => collect($amounts)->map(fn ($amount, $index) => [
                            'installment_number' => $index + 1, 'amount_cents' => $amount,
                        ])->all(),
                        'notes' => $dto->notes,
                        'source' => 'manual',
                    ],
                );
                $this->resolveCreditCardInvoice->refreshTotals($invoice);

                return $transaction->fresh(['creditCard', 'creditCardInvoice', 'expenseAccount', 'journalEntry.lines.chartOfAccount']);
            }
            $firstTransaction = null;
            $invoices = collect();

            foreach ($amounts as $index => $amountCents) {
                $installmentNumber = $index + 1;
                $installmentDate = $purchaseDate->addMonthsNoOverflow($index)->toDateString();
                $invoice = $this->resolveCreditCardInvoice->forPurchaseDate($wallet, $creditCard, $installmentDate);
                $description = $installmentsTotal > 1
                    ? sprintf('%s (%d/%d)', $dto->description, $installmentNumber, $installmentsTotal)
                    : $dto->description;

                $journalEntry = $this->createJournalEntry->execute([
                    'wallet_id' => $wallet->id,
                    'entry_date' => $installmentDate,
                    'description' => 'Compra no cartão: '.$description,
                    'lines' => [
                        [
                            'chart_of_account_id' => $expenseAccount->id,
                            'type' => 'debit',
                            'amount_cents' => $amountCents,
                        ],
                        [
                            'chart_of_account_id' => $mainCard->liability_account_id,
                            'type' => 'credit',
                            'amount_cents' => $amountCents,
                        ],
                    ],
                ]);

                $transaction = CreditCardTransaction::query()->create([
                    'parent_transaction_id' => $firstTransaction?->id,
                    'wallet_id' => $wallet->id,
                    'credit_card_id' => $creditCard->id,
                    'credit_card_invoice_id' => $invoice->id,
                    'expense_account_id' => $expenseAccount->id,
                    'journal_entry_id' => $journalEntry->id,
                    'purchase_date' => $installmentDate,
                    'merchant_name' => $dto->merchantName,
                    'description' => $dto->description,
                    'amount_cents' => $amountCents,
                    'installments_total' => $installmentsTotal,
                    'installment_number' => $installmentNumber,
                    'status' => 'draft',
                    'notes' => $dto->notes,
                ]);

                if (! $firstTransaction) {
                    $firstTransaction = $transaction;
                }

                $invoices->put($invoice->id, $invoice);
            }

            $invoices->each(fn ($invoice) => $this->resolveCreditCardInvoice->refreshTotals($invoice));

            return $firstTransaction->fresh([
                'creditCard',
                'creditCardInvoice',
                'expenseAccount',
                'journalEntry.lines.chartOfAccount',
                'childInstallments.creditCardInvoice',
            ]);
        });
    }
}
