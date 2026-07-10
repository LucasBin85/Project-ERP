<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Models\ChartOfAccount;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\PostJournalEntry;
use Carbon\CarbonImmutable;
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

            $installmentsTotal = max(1, $dto->installmentsTotal);
            $amounts = $this->splitAmount($dto->amountCents, $installmentsTotal);
            $purchaseDate = CarbonImmutable::parse($dto->purchaseDate)->startOfDay();
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
                    'description' => 'Compra no cartão: ' . $description,
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

                $journalEntry = $this->postJournalEntry->handle($journalEntry);

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
                    'status' => 'posted',
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

    private function splitAmount(int $totalCents, int $installments): array
    {
        $base = intdiv($totalCents, $installments);
        $remainder = $totalCents % $installments;
        $amounts = [];

        for ($i = 0; $i < $installments; $i++) {
            $amounts[] = $base + ($i < $remainder ? 1 : 0);
        }

        return $amounts;
    }
}
