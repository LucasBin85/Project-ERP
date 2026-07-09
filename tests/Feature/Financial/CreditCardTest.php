<?php

use App\DTOs\Financial\CreditCardDTO;
use App\DTOs\Financial\CreditCardPaymentDTO;
use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Models\ChartOfAccount;
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

it('creates a posted journal entry when registering a credit card purchase', function () {
    $wallet = createTestWalletWithCardGroup();

    $expenseAccount = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

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

    expect($transaction->status)->toBe('posted')
        ->and($transaction->journalEntry->status)->toBe('posted')
        ->and($transaction->journalEntry->is_balanced)->toBeTrue();

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

it('creates a posted journal entry when paying a credit card invoice', function () {
    $wallet = createTestWalletWithCardGroup();

    $expenseAccount = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

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

    app(CreateCreditCardTransaction::class)->execute(
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
            bankAccountId: $bankAccount->id,
            paymentDate: '2026-07-15',
            amountCents: 12590,
            description: 'Pagamento fatura Nubank',
        ),
    );

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalLine::query()->count())->toBe(4)
        ->and($payment->journalEntry->status)->toBe('posted')
        ->and($payment->journalEntry->is_balanced)->toBeTrue();

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
