<?php

use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\BuildBankAccountWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('builds bank accounts overview with current balances', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $equity = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

    AccountingTestHelper::createPostedEntry($wallet, now()->subDays(3)->toDateString(), [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$equity, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, now()->subDay()->toDateString(), [
        [$expense, 'debit', 25000],
        [$bankAccount->chartOfAccount, 'credit', 25000],
    ]);

    $data = app(BuildBankAccountWorkspace::class)->index($wallet);

    expect($data['summary']['total_current_balance_cents'])->toBe(75000)
        ->and($data['summary']['accounts_count'])->toBe(1)
        ->and($data['accounts'][0]['current_balance_cents'])->toBe(75000)
        ->and($data['accounts'][0]['last_transaction_at'])->not->toBeNull();
});

it('builds a bank account workspace with recent transactions and actions', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );

    $equity = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

    AccountingTestHelper::createPostedEntry($wallet, now()->subDays(3)->toDateString(), [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$equity, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, now()->subDay()->toDateString(), [
        [$expense, 'debit', 25000],
        [$bankAccount->chartOfAccount, 'credit', 25000],
    ]);

    $data = app(BuildBankAccountWorkspace::class)->show($wallet, $bankAccount);

    expect($data['summary']['current_balance_cents'])->toBe(75000)
        ->and($data['summary']['month_inflows_cents'])->toBe(100000)
        ->and($data['summary']['month_outflows_cents'])->toBe(25000)
        ->and($data['recent_transactions'])->toHaveCount(2)
        ->and($data['actions']['statement_url'])->toContain('/bank-accounts/'.$bankAccount->id.'/statement')
        ->and($data['actions'])->not->toHaveKey('ofx_import_url')
        ->and($data['actions'])->not->toHaveKey('reconciliation_url');
});
