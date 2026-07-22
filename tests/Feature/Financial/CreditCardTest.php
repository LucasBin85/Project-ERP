<?php

use App\DTOs\Financial\CreditCardDTO;
use App\DTOs\Financial\CreditCardPaymentDTO;
use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Models\ChartOfAccount;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\CreateCreditCardTransaction;
use App\Services\Financial\PayCreditCardInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function createCreditCardLiabilityGroup(Wallet $wallet): void
{
    $passivo = ChartOfAccount::query()->updateOrCreate(
        [
            'wallet_id' => $wallet->id,
            'code' => '2',
        ],
        [
            'name' => 'Passivo',
            'type' => 'passivo',
            'normal_balance' => 'credit',
            'allows_posting' => false,
        ],
    );

    ChartOfAccount::query()->updateOrCreate(
        [
            'wallet_id' => $wallet->id,
            'code' => '2.2',
        ],
        [
            'parent_id' => $passivo->id,
            'name' => 'Cartões de Crédito',
            'type' => 'passivo',
            'normal_balance' => 'credit',
            'allows_posting' => false,
        ],
    );
}

function createTestWalletWithCardGroup(): Wallet
{
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    createCreditCardLiabilityGroup($wallet);

    return $wallet;
}

it('creates a main credit card with a liability account and linked bank account', function () {
    $wallet = createTestWalletWithCardGroup();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $creditCard = app(CreateCreditCard::class)->execute(
        $wallet,
        new CreditCardDTO(
            name: 'Nubank Roxinho',
            issuerName: 'Nubank',
            network: 'mastercard',
            cardType: 'main',
            closingDay: 5,
            dueDay: 15,
            bestPurchaseDay: 6,
            creditLimitCents: 500000,
            bankAccountId: $bankAccount->id,
            holderName: 'Lucas',
            lastFour: '1234',
        ),
    );

    expect($creditCard->card_type)->toBe('main')
        ->and($creditCard->bank_account_id)->toBe($bankAccount->id)
        ->and($creditCard->liabilityAccount->code)->toBe('2.2.001')
        ->and($creditCard->liabilityAccount->type)->toBe('passivo')
        ->and($creditCard->liabilityAccount->allows_posting)->toBeTrue();
});

it('creates a virtual credit card sharing parent invoice settings', function () {
    $wallet = createTestWalletWithCardGroup();

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $mainCard = app(CreateCreditCard::class)->execute(
        $wallet,
        new CreditCardDTO(
            name: 'Nubank Principal',
            issuerName: 'Nubank',
            network: 'mastercard',
            cardType: 'main',
            closingDay: 5,
            dueDay: 15,
            bestPurchaseDay: 6,
            creditLimitCents: 500000,
            bankAccountId: $bankAccount->id,
        ),
    );

    $virtualCard = app(CreateCreditCard::class)->execute(
        $wallet,
        new CreditCardDTO(
            name: 'Nubank Virtual',
            issuerName: 'Nubank',
            network: 'mastercard',
            cardType: 'virtual',
            closingDay: 20,
            dueDay: 28,
            bestPurchaseDay: 21,
            creditLimitCents: 100000,
            parentCardId: $mainCard->id,
            lastFour: '9999',
        ),
    );

    expect($virtualCard->parent_card_id)->toBe($mainCard->id)
        ->and($virtualCard->liability_account_id)->toBe($mainCard->liability_account_id)
        ->and($virtualCard->bank_account_id)->toBe($mainCard->bank_account_id)
        ->and($virtualCard->closing_day)->toBe($mainCard->closing_day)
        ->and($virtualCard->due_day)->toBe($mainCard->due_day)
        ->and($virtualCard->best_purchase_day)->toBe($mainCard->best_purchase_day)
        ->and($virtualCard->credit_limit_cents)->toBe($mainCard->credit_limit_cents);
});

it('creates a posted journal entry and monthly invoice when registering a credit card purchase', function () {
    $wallet = createTestWalletWithCardGroup();

    $expenseAccount = AccountingTestHelper::account($wallet, '5.9.91', 'Despesa Administrativa', 'despesa', 'debit');

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $creditCard = app(CreateCreditCard::class)->execute(
        $wallet,
        new CreditCardDTO(
            name: 'Nubank Principal',
            issuerName: 'Nubank',
            network: 'mastercard',
            cardType: 'main',
            closingDay: 5,
            dueDay: 15,
            bestPurchaseDay: 6,
            creditLimitCents: 500000,
            bankAccountId: $bankAccount->id,
        ),
    );

    $transaction = app(CreateCreditCardTransaction::class)->execute(
        $wallet,
        new CreditCardTransactionDTO(
            creditCardId: $creditCard->id,
            expenseAccountId: $expenseAccount->id,
            purchaseDate: '2026-07-10',
            merchantName: 'Mercado Central',
            description: 'Compra no mercado',
            amountCents: 12590,
        ),
    );

    $invoice = $transaction->creditCardInvoice;

    expect($transaction->status)->toBe('posted')
        ->and($transaction->journalEntry->status)->toBe('posted')
        ->and($transaction->journalEntry->is_balanced)->toBeTrue()
        ->and($invoice->reference_year)->toBe(2026)
        ->and($invoice->reference_month)->toBe(8)
        ->and($invoice->starts_at->toDateString())->toBe('2026-07-06')
        ->and($invoice->closes_at->toDateString())->toBe('2026-08-05')
        ->and($invoice->due_at->toDateString())->toBe('2026-08-15')
        ->and($invoice->total_cents)->toBe(12590)
        ->and($invoice->balance_cents)->toBe(12590)
        ->and($invoice->status)->toBe('open');

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $transaction->journal_entry_id,
        'chart_of_account_id' => $expenseAccount->id,
        'type' => 'debit',
        'amount_cents' => 12590,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $transaction->journal_entry_id,
        'chart_of_account_id' => $creditCard->liability_account_id,
        'type' => 'credit',
        'amount_cents' => 12590,
    ]);
});

it('splits a credit card purchase into installments across monthly invoices', function () {
    $wallet = createTestWalletWithCardGroup();

    $expenseAccount = AccountingTestHelper::account($wallet, '5.9.91', 'Despesa Administrativa', 'despesa', 'debit');

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $creditCard = app(CreateCreditCard::class)->execute(
        $wallet,
        new CreditCardDTO(
            name: 'Nubank Principal',
            issuerName: 'Nubank',
            network: 'mastercard',
            cardType: 'main',
            closingDay: 5,
            dueDay: 15,
            bestPurchaseDay: 6,
            creditLimitCents: 500000,
            bankAccountId: $bankAccount->id,
        ),
    );

    $firstInstallment = app(CreateCreditCardTransaction::class)->execute(
        $wallet,
        new CreditCardTransactionDTO(
            creditCardId: $creditCard->id,
            expenseAccountId: $expenseAccount->id,
            purchaseDate: '2026-07-10',
            merchantName: 'Loja Tech',
            description: 'Compra parcelada',
            amountCents: 90000,
            installmentsTotal: 3,
            installmentNumber: 1,
        ),
    );

    $installments = CreditCardTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('description', 'Compra parcelada')
        ->orderBy('installment_number')
        ->get();

    expect($installments)->toHaveCount(3)
        ->and(JournalEntry::query()->count())->toBe(3)
        ->and(JournalLine::query()->count())->toBe(6)
        ->and($firstInstallment->parent_transaction_id)->toBeNull()
        ->and($installments[1]->parent_transaction_id)->toBe($firstInstallment->id)
        ->and($installments[2]->parent_transaction_id)->toBe($firstInstallment->id);

    expect($installments->pluck('amount_cents')->all())->toBe([30000, 30000, 30000])
        ->and($installments->pluck('purchase_date')->map->toDateString()->all())->toBe([
            '2026-07-10',
            '2026-08-10',
            '2026-09-10',
        ]);

    $invoices = CreditCardInvoice::query()
        ->where('credit_card_id', $creditCard->id)
        ->orderBy('reference_month')
        ->get();

    expect($invoices)->toHaveCount(3)
        ->and($invoices->pluck('reference_month')->all())->toBe([8, 9, 10])
        ->and($invoices->pluck('total_cents')->all())->toBe([30000, 30000, 30000])
        ->and($invoices->pluck('balance_cents')->all())->toBe([30000, 30000, 30000]);
});

it('creates a posted journal entry when paying a specific credit card invoice', function () {
    $wallet = createTestWalletWithCardGroup();

    $expenseAccount = AccountingTestHelper::account($wallet, '5.9.91', 'Despesa Administrativa', 'despesa', 'debit');

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $creditCard = app(CreateCreditCard::class)->execute(
        $wallet,
        new CreditCardDTO(
            name: 'Nubank Principal',
            issuerName: 'Nubank',
            network: 'mastercard',
            cardType: 'main',
            closingDay: 5,
            dueDay: 15,
            bestPurchaseDay: 6,
            creditLimitCents: 500000,
            bankAccountId: $bankAccount->id,
        ),
    );

    $transaction = app(CreateCreditCardTransaction::class)->execute(
        $wallet,
        new CreditCardTransactionDTO(
            creditCardId: $creditCard->id,
            expenseAccountId: $expenseAccount->id,
            purchaseDate: '2026-07-10',
            merchantName: 'Mercado Central',
            description: 'Compra no mercado',
            amountCents: 12590,
        ),
    );

    $payment = app(PayCreditCardInvoice::class)->execute(
        $wallet,
        new CreditCardPaymentDTO(
            creditCardId: $creditCard->id,
            creditCardInvoiceId: $transaction->credit_card_invoice_id,
            bankAccountId: $bankAccount->id,
            paymentDate: '2026-08-15',
            amountCents: 12590,
            description: 'Pagamento fatura Nubank',
        ),
    );

    $invoice = CreditCardInvoice::query()->findOrFail($transaction->credit_card_invoice_id);

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalLine::query()->count())->toBe(4)
        ->and($payment->credit_card_invoice_id)->toBe($invoice->id)
        ->and($payment->journalEntry->status)->toBe('posted')
        ->and($payment->journalEntry->is_balanced)->toBeTrue()
        ->and($invoice->paid_cents)->toBe(12590)
        ->and($invoice->balance_cents)->toBe(0)
        ->and($invoice->status)->toBe('paid');

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $payment->journal_entry_id,
        'chart_of_account_id' => $creditCard->liability_account_id,
        'type' => 'debit',
        'amount_cents' => 12590,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $payment->journal_entry_id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'credit',
        'amount_cents' => 12590,
    ]);
});
