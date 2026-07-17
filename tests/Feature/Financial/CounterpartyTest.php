<?php

use App\Models\User;
use App\Models\Supplier;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('creates suppliers with valid control and default expense accounts in the active wallet', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();
    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->post(route('suppliers.store'), [
        'name' => 'CEEE', 'payable_account_id' => $control->id, 'default_expense_account_id' => $expense->id, 'active' => true,
    ])->assertRedirect(route('suppliers.index'));
    $this->assertDatabaseHas('suppliers', ['wallet_id' => $wallet->id, 'name' => 'CEEE', 'payable_account_id' => $control->id]);
});

it('automatically creates and links payable and expense accounts for a supplier', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->post(route('suppliers.store'), [
        'name' => 'CEEE', 'default_expense_name' => 'Energia elétrica', 'active' => true,
    ])->assertRedirect(route('suppliers.index'));

    $supplier = Supplier::query()->where('wallet_id', $wallet->id)->where('name', 'CEEE')->firstOrFail();
    expect($supplier->payableAccount->parent->code)->toBe('2.1')
        ->and($supplier->payableAccount->name)->toBe('CEEE')
        ->and($supplier->payableAccount->type)->toBe('passivo')
        ->and($supplier->payableAccount->allows_posting)->toBeTrue()
        ->and($supplier->defaultExpenseAccount->parent->code)->toBe('5.1')
        ->and($supplier->defaultExpenseAccount->name)->toBe('Energia elétrica')
        ->and($supplier->defaultExpenseAccount->type)->toBe('despesa')
        ->and($supplier->defaultExpenseAccount->allows_posting)->toBeTrue();
});

it('quick creates a valid supplier and returns both linked accounts', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $response = $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('suppliers.quick-store'), ['name' => 'Fornecedor rápido', 'default_expense_name' => 'Fretes', 'active' => true]);

    $response->assertCreated()->assertJsonPath('supplier.name', 'Fornecedor rápido')
        ->assertJsonPath('supplier.payable_account.name', 'Fornecedor rápido')
        ->assertJsonPath('supplier.default_expense_account.name', 'Fretes');
    $supplier = Supplier::query()->findOrFail($response->json('supplier.id'));
    expect($supplier->payableAccount->parent->code)->toBe('2.1')
        ->and($supplier->defaultExpenseAccount->parent->code)->toBe('5.1');
});

it('rejects supplier accounts from another wallet or with invalid types', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $other = User::factory()->create()->wallets()->firstOrFail();
    $foreignControl = $other->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail();
    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->post(route('suppliers.store'), [
        'name' => 'Inválido', 'payable_account_id' => $foreignControl->id, 'default_expense_account_id' => $revenue->id,
    ])->assertSessionHasErrors(['payable_account_id', 'default_expense_account_id']);
});

it('creates customers and rejects accounts outside their accounting roles', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail();
    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->post(route('customers.store'), [
        'name' => 'Cliente A', 'receivable_account_id' => $control->id, 'default_revenue_account_id' => $revenue->id, 'active' => true,
    ])->assertRedirect(route('customers.index'));
    $this->assertDatabaseHas('customers', ['wallet_id' => $wallet->id, 'name' => 'Cliente A', 'receivable_account_id' => $control->id]);

    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();
    $this->post(route('customers.store'), ['name' => 'Inválido', 'receivable_account_id' => $expense->id, 'default_revenue_account_id' => $expense->id])
        ->assertSessionHasErrors(['receivable_account_id', 'default_revenue_account_id']);
});

it('automatically creates and links receivable and revenue accounts for a customer', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->post(route('customers.store'), [
        'name' => 'Cliente Novo', 'default_revenue_name' => 'Serviços recorrentes', 'active' => true,
    ])->assertRedirect(route('customers.index'));

    $customer = Customer::query()->where('wallet_id', $wallet->id)->where('name', 'Cliente Novo')->firstOrFail();
    expect($customer->receivableAccount->parent->code)->toBe('1.2')
        ->and($customer->receivableAccount->name)->toBe('Cliente Novo')
        ->and($customer->defaultRevenueAccount->parent->code)->toBe('4.1')
        ->and($customer->defaultRevenueAccount->name)->toBe('Serviços recorrentes');
});

it('quick creates a valid customer and returns both linked accounts', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $response = $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('customers.quick-store'), ['name' => 'Cliente rápido', 'default_revenue_name' => 'Mensalidade', 'active' => true]);

    $response->assertCreated()->assertJsonPath('customer.name', 'Cliente rápido')
        ->assertJsonPath('customer.receivable_account.name', 'Cliente rápido')
        ->assertJsonPath('customer.default_revenue_account.name', 'Mensalidade');
    $customer = Customer::query()->findOrFail($response->json('customer.id'));
    expect($customer->receivableAccount->parent->code)->toBe('1.2')
        ->and($customer->defaultRevenueAccount->parent->code)->toBe('4.1');
});

it('rejects foreign wallet accounts in quick creation', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $foreignWallet = User::factory()->create()->wallets()->firstOrFail();
    $foreignPayable = $foreignWallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();
    $foreignExpense = $foreignWallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->postJson(route('suppliers.quick-store'), [
        'name' => 'Inválido', 'payable_account_id' => $foreignPayable->id, 'default_expense_account_id' => $foreignExpense->id, 'active' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['payable_account_id', 'default_expense_account_id']);
});

it('rejects normalized duplicate supplier names in the backend', function (string $duplicate) {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('suppliers.quick-store'), ['name' => $duplicate, 'active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', 'Já existe um fornecedor com este nome.');
})->with(['trimmed' => '  Fornecedores Diversos  ', 'case insensitive' => 'FORNECEDORES DIVERSOS']);

it('rejects normalized duplicate customer names in the backend', function (string $duplicate) {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail();
    Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Teste', 'receivable_account_id' => $control->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('customers.quick-store'), ['name' => $duplicate, 'active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['name'])
        ->assertJsonPath('errors.name.0', 'Já existe um cliente com este nome.');
})->with(['trimmed' => '  Cliente Teste  ', 'case insensitive' => 'CLIENTE TESTE']);

it('normalizes whitespace before storing a new counterparty name', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])
        ->postJson(route('suppliers.quick-store'), ['name' => '  Novo   Fornecedor  ', 'active' => true])->assertCreated();

    $this->assertDatabaseHas('suppliers', ['wallet_id' => $wallet->id, 'name' => 'Novo Fornecedor']);
});

it('lists only valid suppliers in the new payable title', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $otherWallet = User::factory()->create()->wallets()->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();
    Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'VÃ¡lido', 'payable_account_id' => $control->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Inativo', 'payable_account_id' => $control->id, 'default_expense_account_id' => $expense->id, 'active' => false]);
    $foreignControl = $otherWallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->firstOrFail();
    $foreignExpense = $otherWallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->firstOrFail();
    Supplier::query()->create(['wallet_id' => $otherWallet->id, 'name' => 'Outra carteira', 'payable_account_id' => $foreignControl->id, 'default_expense_account_id' => $foreignExpense->id, 'active' => true]);

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->get(route('accounts-payable.create'))
        ->assertInertia(fn (Assert $page) => $page->component('Financial/AccountsPayable/Create')
            ->has('suppliers', 7)
            ->has('payableControlAccounts', 6)
            ->has('expenseAccounts', 6)
            ->where('suppliers.5.name', 'VÃ¡lido')
            ->has('suppliers.5.payable_account')
            ->has('suppliers.5.default_expense_account'));
});

it('lists only valid customers in the new receivable title', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->firstOrFail();
    Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente vÃ¡lido', 'receivable_account_id' => $control->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);
    Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente inativo', 'receivable_account_id' => $control->id, 'default_revenue_account_id' => $revenue->id, 'active' => false]);

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->get(route('accounts-receivable.create'))
        ->assertInertia(fn (Assert $page) => $page->component('Financial/AccountsReceivable/Create')
            ->has('customers', 1)
            ->has('receivableControlAccounts', 1)
            ->has('revenueAccounts', 1)
            ->where('customers.0.name', 'Cliente vÃ¡lido')
            ->has('customers.0.receivable_account')
            ->has('customers.0.default_revenue_account'));
});
