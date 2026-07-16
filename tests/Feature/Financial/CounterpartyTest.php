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
            ->where('customers.0.name', 'Cliente vÃ¡lido')
            ->has('customers.0.receivable_account')
            ->has('customers.0.default_revenue_account'));
});
