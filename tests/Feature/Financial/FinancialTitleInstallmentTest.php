<?php

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\PayAccountPayableDTO;
use App\DTOs\Financial\ReceiveAccountReceivableDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\FinancialTitleSeries;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\Customer;
use App\Models\User;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\PayAccountPayable;
use App\Services\Financial\ReceiveAccountReceivable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('creates payable installments with one total provision and distributes cents', function () {
    $wallet = User::factory()->create()->wallets()->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();

    $first = app(CreateAccountPayable::class)->execute($wallet, new AccountPayableDTO(
        expenseAccountId: $expense->id, payeeName: 'Fornecedor', description: 'Aquisição parcelada',
        dueDate: '2026-08-10', amountCents: 10000, payableAccountId: $control->id,
        mode: 'installment', installmentCount: 3, competenceDate: '2026-07-28',
    ));

    $series = FinancialTitleSeries::firstOrFail();
    expect($series->payables()->pluck('amount_cents')->all())->toBe([3334, 3333, 3333])
        ->and($series->payables()->sum('amount_cents'))->toBe(10000)
        ->and($series->provision_journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::count())->toBe(1)
        ->and($first->provision_journal_entry_id)->toBeNull();

    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.991', 'Banco');
    app(PayAccountPayable::class)->execute($wallet, $first, new PayAccountPayableDTO($bank->id, '2026-08-10'));
    expect(AccountPayable::where('status', 'paid')->count())->toBe(1)
        ->and(AccountPayable::where('status', 'pending')->count())->toBe(2)
        ->and(JournalEntry::count())->toBe(2);
});

it('creates receivable installments with one total provision and settles only one installment', function () {
    $wallet = User::factory()->create()->wallets()->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail();

    $first = app(CreateAccountReceivable::class)->execute($wallet, new AccountReceivableDTO(
        revenueAccountId: $revenue->id, customerName: 'Cliente', description: 'Venda parcelada',
        dueDate: '2026-08-10', amountCents: 120000, receivableAccountId: $control->id,
        mode: 'installment', installmentCount: 3, competenceDate: '2026-07-28',
    ));

    $series = FinancialTitleSeries::firstOrFail();
    expect($series->receivables()->sum('amount_cents'))->toBe(120000)
        ->and(JournalEntry::count())->toBe(1);

    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.992', 'Banco');
    app(ReceiveAccountReceivable::class)->execute($wallet, $first, new ReceiveAccountReceivableDTO($bank->id, '2026-08-10'));
    expect(AccountReceivable::where('status', 'received')->count())->toBe(1)
        ->and(AccountReceivable::where('status', 'pending')->count())->toBe(2)
        ->and(JournalEntry::count())->toBe(2);
});

it('accepts adjusted payable installments and rejects a divergent total', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();
    $supplier = Supplier::create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor Ajustado', 'payable_account_id' => $control->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $payload = [
        'supplier_id' => $supplier->id, 'description' => 'Compra ajustada', 'amount_cents' => 10000,
        'due_date' => '2026-08-10', 'competence_date' => '2026-07-28', 'mode' => 'installment',
        'installment_count' => 3, 'interval_months' => 1,
        'installments' => [
            ['due_date' => '2026-08-12', 'amount_cents' => 5000],
            ['due_date' => '2026-09-15', 'amount_cents' => 3000],
            ['due_date' => '2026-10-20', 'amount_cents' => 2000],
        ],
    ];

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->post(route('accounts-payable.store'), $payload)->assertRedirect(route('accounts-payable.index'));
    expect(AccountPayable::orderBy('installment_number')->pluck('amount_cents')->all())->toBe([5000, 3000, 2000])
        ->and(AccountPayable::orderBy('installment_number')->pluck('due_date')->map->toDateString()->all())->toBe(['2026-08-12', '2026-09-15', '2026-10-20'])
        ->and(JournalEntry::count())->toBe(1);

    $payload['installments'][2]['amount_cents'] = 1900;
    $this->post(route('accounts-payable.store'), $payload)
        ->assertSessionHasErrors(['installments' => 'A soma das parcelas precisa ser igual ao valor total.']);
    expect(FinancialTitleSeries::count())->toBe(1);
});

it('accepts adjusted receivable installments and rejects a divergent total', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail();
    $customer = Customer::create(['wallet_id' => $wallet->id, 'name' => 'Cliente Ajustado', 'receivable_account_id' => $control->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);
    $payload = [
        'customer_id' => $customer->id, 'description' => 'Venda ajustada', 'amount_cents' => 10000,
        'due_date' => '2026-08-10', 'competence_date' => '2026-07-28', 'mode' => 'installment',
        'installment_count' => 2, 'interval_months' => 1,
        'installments' => [
            ['due_date' => '2026-08-18', 'amount_cents' => 7000],
            ['due_date' => '2026-09-22', 'amount_cents' => 3000],
        ],
    ];

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->post(route('accounts-receivable.store'), $payload)->assertRedirect(route('accounts-receivable.index'));
    expect(AccountReceivable::orderBy('installment_number')->pluck('amount_cents')->all())->toBe([7000, 3000])
        ->and(JournalEntry::count())->toBe(1);

    $payload['installments'][1]['amount_cents'] = 2999;
    $this->post(route('accounts-receivable.store'), $payload)
        ->assertSessionHasErrors(['installments' => 'A soma das parcelas precisa ser igual ao valor total.']);
    expect(FinancialTitleSeries::count())->toBe(1);
});
