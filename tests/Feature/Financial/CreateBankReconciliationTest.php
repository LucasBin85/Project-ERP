<?php

use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateBankReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('creates a completed reconciliation when selected movements match statement balance', function () {
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

    $bankLineIds = JournalLine::query()
        ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
        ->whereHas('journalEntry', fn ($query) => $query
            ->whereDate('entry_date', '>=', '2026-07-01')
            ->whereDate('entry_date', '<=', '2026-07-31'))
        ->pluck('id')
        ->all();

    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO(
            bankAccountId: $bankAccount->id,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            statementBalanceCents: 138000,
            journalLineIds: $bankLineIds,
        ),
    );

    expect($reconciliation->status)->toBe('completed')
        ->and($reconciliation->opening_balance_cents)->toBe(100000)
        ->and($reconciliation->book_balance_cents)->toBe(138000)
        ->and($reconciliation->reconciled_balance_cents)->toBe(138000)
        ->and($reconciliation->statement_balance_cents)->toBe(138000)
        ->and($reconciliation->difference_cents)->toBe(0)
        ->and($reconciliation->items)->toHaveCount(2);
});

it('keeps reconciliation as draft when there is a difference', function () {
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

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 100000],
        [$capital, 'credit', 100000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 50000],
        [$revenue, 'credit', 50000],
    ]);

    $bankLineIds = JournalLine::query()
        ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
        ->whereHas('journalEntry', fn ($query) => $query
            ->whereDate('entry_date', '>=', '2026-07-01')
            ->whereDate('entry_date', '<=', '2026-07-31'))
        ->pluck('id')
        ->all();

    $reconciliation = app(CreateBankReconciliation::class)->execute(
        $wallet,
        new BankReconciliationDTO(
            bankAccountId: $bankAccount->id,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            statementBalanceCents: 140000,
            journalLineIds: $bankLineIds,
        ),
    );

    expect($reconciliation->status)->toBe('draft')
        ->and($reconciliation->opening_balance_cents)->toBe(100000)
        ->and($reconciliation->reconciled_balance_cents)->toBe(150000)
        ->and($reconciliation->statement_balance_cents)->toBe(140000)
        ->and($reconciliation->difference_cents)->toBe(10000);
});
