<?php

use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\BalanceSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

it('calculates assets liabilities equity and includes current period result', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    $bank = AccountingTestHelper::account($wallet, '1.1.1', 'Banco', 'ativo', 'debit');
    $capital = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');
    $revenue = AccountingTestHelper::account($wallet, '4.1', 'Receita de Serviços', 'receita', 'credit');
    $expense = AccountingTestHelper::account($wallet, '5.1', 'Despesas Administrativas', 'despesa', 'debit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-01-01', [
        [$bank, 'debit', 1000000],
        [$capital, 'credit', 1000000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-01', [
        [$bank, 'debit', 850000],
        [$revenue, 'credit', 850000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-06-10', [
        [$expense, 'debit', 35590],
        [$bank, 'credit', 35590],
    ]);

    $data = app(BalanceSheetService::class)
        ->build($wallet, '2026-12-31');

    expect($data->assetsCents())->toBe(1814410)
        ->and($data->liabilitiesCents())->toBe(0)
        ->and($data->currentPeriodResultCents)->toBe(814410)
        ->and($data->equityCents())->toBe(1814410)
        ->and($data->differenceCents())->toBe(0);
});