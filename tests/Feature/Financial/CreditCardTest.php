<?php

use App\DTOs\Financial\CreditCardDTO;
use App\DTOs\Financial\CreditCardPaymentDTO;
use App\DTOs\Financial\CreditCardTransactionDTO;
use App\Models\Bank;
use App\Models\ChartOfAccount;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\CreateCreditCardInstallments;
use App\Services\Financial\CreateCreditCardTransaction;
use App\Services\Financial\LinkCreditCardInvoicePaymentFromBankStatement;
use App\Services\Financial\PayCreditCardInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('distributes installment rounding without losing cents', function () {
    expect(app(CreateCreditCardInstallments::class)->split(1000, 3))->toBe([334, 333, 333]);
});

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
    Bank::query()->firstOrCreate(['code' => '999'], [
        'name' => 'Nubank',
        'short_name' => 'Nubank',
        'ispb' => '99999999',
        'active' => true,
    ]);

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
        ->and($creditCard->bank_account_id)->toBeNull()
        ->and($creditCard->issuerBank->short_name)->toBe('Nubank')
        ->and($creditCard->liabilityAccount->code)->toBe('2.2.001')
        ->and($creditCard->liabilityAccount->type)->toBe('passivo')
        ->and($creditCard->liabilityAccount->allows_posting)->toBeTrue();
});

it('creates a main credit card without a default payment bank account', function () {
    $wallet = createTestWalletWithCardGroup();
    $creditCard = app(CreateCreditCard::class)->execute($wallet, new CreditCardDTO(
        name: 'Cartão sem conta padrão',
        issuerName: 'Nubank',
        network: 'mastercard',
        cardType: 'main',
        closingDay: 5,
        dueDay: 15,
        bestPurchaseDay: 6,
        creditLimitCents: 0,
    ));

    expect($creditCard->bank_account_id)->toBeNull()
        ->and(CreditCardTransaction::query()->count())->toBe(0)
        ->and(CreditCardInvoice::query()->count())->toBe(0);
});

it('inherits the issuing institution from the bank account creation context', function () {
    $wallet = createTestWalletWithCardGroup();
    $user = $wallet->user;
    $nubank = Bank::query()->where('short_name', 'Nubank')->firstOrFail();
    $itau = Bank::query()->create([
        'code' => '341', 'name' => 'Itaú Unibanco', 'short_name' => 'Itaú', 'ispb' => '60701190', 'active' => true,
    ]);
    $account = FinancialTestHelper::bankAccount($wallet, '1.1.2.010', 'Conta Nubank');
    $account->update(['bank_id' => $nubank->id]);

    $payload = [
        'name' => 'Cartão Nubank', 'bank_id' => $nubank->id, 'bank_account_context_id' => $account->id,
        'network' => 'mastercard', 'card_type' => 'main', 'closing_day' => 5, 'due_day' => 15,
        'best_purchase_day' => 6, 'credit_limit_cents' => 100000,
    ];

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->post(route('credit-cards.store'), $payload)->assertSessionHasNoErrors();
    $card = \App\Models\CreditCard::query()->where('name', 'Cartão Nubank')->firstOrFail();
    expect($card->issuer_bank_id)->toBe($nubank->id)->and($card->bank_account_id)->toBeNull();

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->post(route('credit-cards.store'), [...$payload, 'name' => 'Cartão inválido', 'bank_id' => $itau->id])
        ->assertSessionHasErrors('bank_id');
});

it('does not expose a default payment account in the credit card UI', function () {
    expect(file_get_contents(resource_path('js/pages/Financial/CreditCards/Create.vue')))
        ->not->toContain('Conta padrão para pagamento da fatura')
        ->not->toContain('form.issuer_name')
        ->and(file_get_contents(resource_path('js/pages/Financial/CreditCards/Show.vue')))
        ->not->toContain('Pagar fatura')
        ->not->toContain('submitPayment');
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

it('creates a draft journal entry and monthly invoice when registering a credit card purchase', function () {
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

    expect($transaction->status)->toBe('draft')
        ->and($transaction->journalEntry->status)->toBe('draft')
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

it('creates a draft journal entry when paying a specific credit card invoice', function () {
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

    expect(fn () => app(PayCreditCardInvoice::class)->execute(
        $wallet,
        new CreditCardPaymentDTO(
            creditCardId: $creditCard->id,
            creditCardInvoiceId: $transaction->credit_card_invoice_id,
            bankAccountId: $bankAccount->id,
            paymentDate: '2026-08-15',
            amountCents: 12591,
        ),
    ))->toThrow(ValidationException::class);

    $partialPayment = app(PayCreditCardInvoice::class)->execute(
        $wallet,
        new CreditCardPaymentDTO(
            creditCardId: $creditCard->id,
            creditCardInvoiceId: $transaction->credit_card_invoice_id,
            bankAccountId: $bankAccount->id,
            paymentDate: '2026-08-15',
            amountCents: 5000,
            description: 'Pagamento fatura Nubank',
        ),
    );

    $invoice = CreditCardInvoice::query()->findOrFail($transaction->credit_card_invoice_id);
    expect($invoice->paid_cents)->toBe(5000)
        ->and($invoice->balance_cents)->toBe(7590)
        ->and($invoice->status)->toBe('partial');

    $payment = app(PayCreditCardInvoice::class)->execute(
        $wallet,
        new CreditCardPaymentDTO(
            creditCardId: $creditCard->id,
            creditCardInvoiceId: $transaction->credit_card_invoice_id,
            bankAccountId: $bankAccount->id,
            paymentDate: '2026-08-15',
            amountCents: 7590,
            description: 'Pagamento final fatura Nubank',
        ),
    );

    $invoice->refresh();

    expect(JournalEntry::query()->count())->toBe(3)
        ->and(JournalLine::query()->count())->toBe(6)
        ->and($payment->credit_card_invoice_id)->toBe($invoice->id)
        ->and($payment->journalEntry->status)->toBe('draft')
        ->and($payment->journalEntry->is_balanced)->toBeTrue()
        ->and($invoice->paid_cents)->toBe(12590)
        ->and($invoice->balance_cents)->toBe(0)
        ->and($invoice->status)->toBe('paid');

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $payment->journal_entry_id,
        'chart_of_account_id' => $creditCard->liability_account_id,
        'type' => 'debit',
        'amount_cents' => 7590,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $payment->journal_entry_id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'credit',
        'amount_cents' => 7590,
    ]);
});

it('pays an invoice from the bank account where the statement outflow occurred', function () {
    $wallet = createTestWalletWithCardGroup();
    $expense = AccountingTestHelper::account($wallet, '5.9.95', 'Despesa cartão', 'despesa', 'debit');
    $temporary = AccountingTestHelper::account($wallet, '1.9.95', 'Classificação temporária', 'ativo', 'debit');
    $issuerAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.095', 'Conta da emissora');
    $payingAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.096', 'Conta de outro banco');
    $card = app(CreateCreditCard::class)->execute($wallet, new CreditCardDTO(
        name: 'Nubank', issuerName: 'Nubank', network: 'mastercard', cardType: 'main',
        closingDay: 5, dueDay: 15, bestPurchaseDay: 6, creditLimitCents: 100000,
        bankAccountId: $issuerAccount->id,
    ));
    $purchase = app(CreateCreditCardTransaction::class)->execute($wallet, new CreditCardTransactionDTO(
        creditCardId: $card->id, expenseAccountId: $expense->id, purchaseDate: '2026-07-10',
        merchantName: 'Compra', description: 'Compra', amountCents: 10000,
    ));
    $entry = app(CreateJournalEntry::class)->execute([
        'wallet_id' => $wallet->id, 'entry_date' => '2026-08-15', 'description' => 'Saída no extrato',
        'lines' => [
            ['chart_of_account_id' => $temporary->id, 'type' => 'debit', 'amount_cents' => 10000],
            ['chart_of_account_id' => $payingAccount->chart_of_account_id, 'type' => 'credit', 'amount_cents' => 10000],
        ],
    ]);
    $entry->update(['source' => 'ofx']);

    $payment = app(LinkCreditCardInvoicePaymentFromBankStatement::class)->execute(
        $wallet, $payingAccount, $entry, $purchase->credit_card_invoice_id,
    );

    expect($payment->bank_account_id)->toBe($payingAccount->id)
        ->and($card->bank_account_id)->toBeNull();
    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $entry->id, 'chart_of_account_id' => $card->liability_account_id,
        'type' => 'debit', 'amount_cents' => 10000,
    ]);
});
