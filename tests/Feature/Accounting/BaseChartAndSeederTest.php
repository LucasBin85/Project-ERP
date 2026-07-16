<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a structural chart without example bank accounts', function () {
    $wallet = User::factory()->create()->wallets()->firstOrFail();

    expect($wallet->chartOfAccounts()->where('code', '1.1.2')->where('name', 'Bancos')->exists())->toBeTrue()
        ->and($wallet->chartOfAccounts()->whereIn('name', ['Banco Principal', 'Banco Reserva'])->exists())->toBeFalse()
        ->and($wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->count())->toBe(6)
        ->and($wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->count())->toBe(6)
        ->and($wallet->suppliers()->count())->toBe(6)
        ->and($wallet->suppliers()->whereNull('payable_account_id')->exists())->toBeFalse()
        ->and($wallet->suppliers()->whereNull('default_expense_account_id')->exists())->toBeFalse();
});

it('runs the default seeder with structural data only', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('wallets', 0);
    $this->assertDatabaseCount('bank_accounts', 0);
    $this->assertDatabaseCount('accounts_payable', 0);
    $this->assertDatabaseCount('accounts_receivable', 0);
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('bank_statement_import_transactions', 0);
    $this->assertDatabaseHas('banks', ['code' => '001']);
});
