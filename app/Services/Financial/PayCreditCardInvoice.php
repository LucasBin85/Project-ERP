<?php

namespace App\Services\Financial;

use App\DTOs\Financial\CreditCardPaymentDTO;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardPayment;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayCreditCardInvoice
{
    public function __construct(
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly PostJournalEntry $postJournalEntry,
    ) {
    }

    public function execute(Wallet $wallet, CreditCardPaymentDTO $dto): CreditCardPayment
    {
        return DB::transaction(function () use ($wallet, $dto) {
            $creditCard = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where('is_active', true)
                ->with('liabilityAccount')
                ->findOrFail($dto->creditCardId);

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

            $description = $dto->description ?: 'Pagamento fatura: ' . $creditCard->name;

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

            $journalEntry = $this->postJournalEntry->handle($journalEntry);

            return CreditCardPayment::query()->create([
                'wallet_id' => $wallet->id,
                'credit_card_id' => $creditCard->id,
                'bank_account_id' => $bankAccount->id,
                'journal_entry_id' => $journalEntry->id,
                'payment_date' => $dto->paymentDate,
                'amount_cents' => $dto->amountCents,
                'description' => $description,
                'status' => 'posted',
                'notes' => $dto->notes,
            ])->fresh(['creditCard', 'bankAccount', 'journalEntry.lines.chartOfAccount']);
        });
    }
}
