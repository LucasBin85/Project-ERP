<?php

use App\Models\User;
use App\Models\Wallet;
use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Services\Financial\BankStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('builds a bank statement with opening balance transactions and closing balance', function () {
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

    $capital = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$capital, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 50000],
        [$revenue, 'credit', 50000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-02', [
        [$expense, 'debit', 12000],
        [$bankAccount->chartOfAccount, 'credit', 12000],
    ]);

    $statement = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: $bankAccount->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    expect($statement->ready)->toBeTrue()
        ->and($statement->openingBalanceCents)->toBe(100000)
        ->and($statement->totalInflowsCents)->toBe(50000)
        ->and($statement->totalOutflowsCents)->toBe(12000)
        ->and($statement->closingBalanceCents)->toBe(138000)
        ->and($statement->transactions)->toHaveCount(2)
        ->and($statement->transactions[0]['running_balance_cents'])->toBe(150000)
        ->and($statement->transactions[1]['running_balance_cents'])->toBe(138000);
});

it('is not ready without required filters', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $statement = app(BankStatementService::class)->build(
        $wallet,
        new BankStatementFiltersDTO(
            bankAccountId: null,
            startDate: null,
            endDate: null,
        ),
    );

    expect($statement->ready)->toBeFalse()
        ->and($statement->transactions)->toHaveCount(0);
});
