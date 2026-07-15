<?php

use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\BankAccountBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('separates the operational bank statement balance from the posted accounting balance', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco Principal');
    $equity = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa', 'despesa', 'debit');
    $suspense = AccountingTestHelper::account($wallet, '1.1.9', 'A classificar', 'ativo', 'debit');
    $wallet->update(['suspense_account_id' => $suspense->id]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$equity, 'credit', 100000],
    ]);
    AccountingTestHelper::createPostedEntry($wallet, '2026-07-02', [
        [$expense, 'debit', 25000],
        [$bankAccount->chartOfAccount, 'credit', 25000],
    ]);
    AccountingTestHelper::createDraftEntry($wallet, '2026-07-03', [
        [$bankAccount->chartOfAccount, 'debit', 30000],
        [$equity, 'credit', 30000],
    ], 'ofx');
    AccountingTestHelper::createDraftEntry($wallet, '2026-07-04', [
        [$expense, 'debit', 5000],
        [$bankAccount->chartOfAccount, 'credit', 5000],
    ]);
    AccountingTestHelper::createDraftEntry($wallet, '2026-07-05', [
        [$suspense, 'debit', 7000],
        [$bankAccount->chartOfAccount, 'credit', 7000],
    ], 'ofx');

    $balances = app(BankAccountBalanceService::class)->calculate($wallet, $bankAccount);

    expect($balances['statement_balance_cents'])->toBe(93000)
        ->and($balances['accounting_balance_cents'])->toBe(75000);
});

it('calculates multiple account balances in the active wallet and rejects cross-wallet accounts', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Principal',
    ]);
    $otherWallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Outra Carteira',
    ]);

    $first = FinancialTestHelper::bankAccount($wallet, '1.1.2.001', 'Banco A');
    $second = FinancialTestHelper::bankAccount($wallet, '1.1.2.002', 'Banco B');
    $foreign = FinancialTestHelper::bankAccount($otherWallet, '1.1.2.001', 'Banco Externo');
    $equity = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$first->chartOfAccount, 'debit', 40000],
        [$equity, 'credit', 40000],
    ]);
    AccountingTestHelper::createDraftEntry($wallet, '2026-07-02', [
        [$second->chartOfAccount, 'debit', 15000],
        [$equity, 'credit', 15000],
    ], 'ofx');

    $service = app(BankAccountBalanceService::class);
    $balances = $service->calculateMany($wallet, collect([$first, $second]));

    expect($balances[$first->id])->toBe([
        'statement_balance_cents' => 40000,
        'accounting_balance_cents' => 40000,
    ])->and($balances[$second->id])->toBe([
        'statement_balance_cents' => 15000,
        'accounting_balance_cents' => 0,
    ]);

    expect(fn () => $service->calculateMany($wallet, collect([$foreign])))
        ->toThrow(InvalidArgumentException::class);
});
