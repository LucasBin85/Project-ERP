<?php

use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

it('keeps accounting reports posted-only when operational bank drafts exist', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bank = AccountingTestHelper::account($wallet, '1.1.1', 'Banco', 'ativo', 'debit');
    $capital = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesa Administrativa', 'despesa', 'debit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-01-01', [
        [$bank, 'debit', 100000],
        [$capital, 'credit', 100000],
    ]);
    AccountingTestHelper::createPostedEntry($wallet, '2026-06-01', [
        [$bank, 'debit', 20000],
        [$revenue, 'credit', 20000],
    ]);
    AccountingTestHelper::createPostedEntry($wallet, '2026-06-10', [
        [$expense, 'debit', 5000],
        [$bank, 'credit', 5000],
    ]);

    AccountingTestHelper::createDraftEntry($wallet, '2026-06-15', [
        [$bank, 'debit', 50000],
        [$revenue, 'credit', 50000],
    ], 'ofx');
    AccountingTestHelper::createDraftEntry($wallet, '2026-06-16', [
        [$expense, 'debit', 30000],
        [$bank, 'credit', 30000],
    ], 'ofx');

    $incomeStatement = app(IncomeStatementService::class)
        ->build($wallet, '2026-01-01', '2026-12-31');
    $balanceSheet = app(BalanceSheetService::class)
        ->build($wallet, '2026-12-31');
    $trialBalance = app(TrialBalanceService::class)
        ->generate($wallet, '2026-01-01', '2026-12-31');
    $bankRow = $trialBalance['rows']->firstWhere('account_id', $bank->id);

    expect($incomeStatement->revenueCents())->toBe(20000)
        ->and($incomeStatement->expenseCents())->toBe(5000)
        ->and($incomeStatement->netIncomeCents())->toBe(15000)
        ->and($balanceSheet->assetsCents())->toBe(115000)
        ->and($balanceSheet->equityCents())->toBe(115000)
        ->and($balanceSheet->differenceCents())->toBe(0)
        ->and($trialBalance['totals']['debit_cents'])->toBe(125000)
        ->and($trialBalance['totals']['credit_cents'])->toBe(125000)
        ->and($bankRow['debit_cents'])->toBe(120000)
        ->and($bankRow['credit_cents'])->toBe(5000)
        ->and($bankRow['balance_cents'])->toBe(115000);
});
