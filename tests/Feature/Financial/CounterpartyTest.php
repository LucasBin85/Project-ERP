<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
