<?php

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\PayAccountPayable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('stores a payable title and redirects to the existing index route', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();
    $supplier = Supplier::create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor', 'payable_account_id' => $control->id, 'default_expense_account_id' => $expense->id, 'active' => true]);

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->post(route('accounts-payable.store'), [
            'supplier_id' => $supplier->id, 'payable_account_id' => 999999, 'expense_account_id' => 999999,
            'description' => 'Título novo', 'due_date' => '2026-07-31', 'amount_cents' => 10000,
        ])->assertRedirect(route('accounts-payable.index'))->assertSessionHas('success', 'Título a pagar cadastrado com sucesso.');

    $this->assertDatabaseHas('accounts_payable', ['wallet_id' => $wallet->id, 'supplier_id' => $supplier->id, 'payable_account_id' => $control->id, 'expense_account_id' => $expense->id, 'status' => 'pending']);
});

it('creates a pending account payable with a draft accrual entry', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $expenseAccount = AccountingTestHelper::account($wallet, '5.9.1', 'Energia Elétrica', 'despesa', 'debit');
    $payableAccount = AccountingTestHelper::account($wallet, '2.1.1', 'Fornecedores Diversos', 'passivo', 'credit');
    $payableAccount->update(['financial_group' => 'accounts_payable']);

    $accountPayable = app(CreateAccountPayable::class)->execute(
        $wallet,
        new AccountPayableDTO(
            expenseAccountId: $expenseAccount->id,
            payeeName: 'CEEE',
            description: 'Conta de energia julho',
            dueDate: '2026-07-15',
            amountCents: 35000,
            payableAccountId: $payableAccount->id,
        ),
    );

    expect($accountPayable->status)->toBe('pending')
        ->and($accountPayable->amount_cents)->toBe(35000)
        ->and($accountPayable->provision_journal_entry_id)->not->toBeNull()
        ->and($accountPayable->payment_journal_entry_id)->toBeNull();

    expect(JournalEntry::query()->count())->toBe(1)
        ->and($accountPayable->provisionJournalEntry->status)->toBe('draft');
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $accountPayable->provision_journal_entry_id, 'chart_of_account_id' => $expenseAccount->id, 'type' => 'debit']);
    $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $accountPayable->provision_journal_entry_id, 'chart_of_account_id' => $payableAccount->id, 'type' => 'credit']);
});

it('pays an account payable against its control account and keeps the entry in draft', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $expenseAccount = AccountingTestHelper::account($wallet, '5.9.1', 'Energia Elétrica', 'despesa', 'debit');
    $payableAccount = AccountingTestHelper::account($wallet, '2.1.1', 'Fornecedores Diversos', 'passivo', 'credit');
    $payableAccount->update(['financial_group' => 'accounts_payable']);

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $accountPayable = app(CreateAccountPayable::class)->execute(
        $wallet,
        new AccountPayableDTO(
            expenseAccountId: $expenseAccount->id,
            payeeName: 'CEEE',
            description: 'Conta de energia julho',
            dueDate: '2026-07-15',
            amountCents: 35000,
            payableAccountId: $payableAccount->id,
        ),
    );

    $paidAccountPayable = app(PayAccountPayable::class)->execute(
        $wallet,
        $accountPayable,
        new PayAccountPayableDTO(
            bankAccountId: $bankAccount->id,
            paidAt: '2026-07-10',
        ),
    );

    expect($paidAccountPayable->status)->toBe('paid')
        ->and($paidAccountPayable->paid_at->toDateString())->toBe('2026-07-10')
        ->and($paidAccountPayable->bank_account_id)->toBe($bankAccount->id)
        ->and($paidAccountPayable->payment_journal_entry_id)->not->toBeNull()
        ->and($paidAccountPayable->paymentJournalEntry->status)->toBe('draft')
        ->and($paidAccountPayable->paymentJournalEntry->is_balanced)->toBeTrue();

    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalLine::query()->count())->toBe(4);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $paidAccountPayable->payment_journal_entry_id,
        'chart_of_account_id' => $payableAccount->id,
        'type' => 'debit',
        'amount_cents' => 35000,
    ]);

    $this->assertDatabaseHas('journal_lines', [
        'journal_entry_id' => $paidAccountPayable->payment_journal_entry_id,
        'chart_of_account_id' => $bankAccount->chart_of_account_id,
        'type' => 'credit',
        'amount_cents' => 35000,
    ]);
});
