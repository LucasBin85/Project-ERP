<?php

use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\IncomeStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

it('calculates revenues expenses and net income from posted entries', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bank = AccountingTestHelper::account($wallet, '1.1.1', 'Banco', 'ativo', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesas Administrativas', 'despesa', 'debit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-01', [
        [$bank, 'debit', 850000],
        [$revenue, 'credit', 850000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-10', [
        [$expense, 'debit', 35590],
        [$bank, 'credit', 35590],
    ]);

    $data = app(IncomeStatementService::class)
        ->build($wallet, '2026-01-01', '2026-12-31');

    expect($data->revenueCents())->toBe(850000)
        ->and($data->expenseCents())->toBe(35590)
        ->and($data->netIncomeCents())->toBe(814410);
});