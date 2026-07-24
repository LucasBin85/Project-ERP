<?php

use App\DTOs\Financial\AccountPayableDTO;
use App\DTOs\Financial\AccountReceivableDTO;
use App\DTOs\Financial\CreditCardDTO;
use App\DTOs\Financial\CreditCardTransactionDTO;
use App\DTOs\Financial\DashboardFiltersDTO;
use App\Models\Bank;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\BuildFinancialDashboard;
use App\Services\Financial\CreateAccountPayable;
use App\Services\Financial\CreateAccountReceivable;
use App\Services\Financial\CreateCreditCard;
use App\Services\Financial\CreateCreditCardTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function createDashboardCreditCardLiabilityGroup(Wallet $wallet): void
{
    $passivo = ChartOfAccount::query()->updateOrCreate(
        [
            'wallet_id' => $wallet->id,
            'code' => '2',
        ],
        [
            'name' => 'Passivo',
            'type' => 'passivo',
            'normal_balance' => 'credit',
            'allows_posting' => false,
        ],
    );

    ChartOfAccount::query()->updateOrCreate(
        [
            'wallet_id' => $wallet->id,
            'code' => '2.2',
        ],
        [
            'parent_id' => $passivo->id,
            'name' => 'Cartões de Crédito',
            'type' => 'passivo',
            'normal_balance' => 'credit',
            'allows_posting' => false,
        ],
    );
}

it('builds the financial dashboard with realized and projected data', function () {
    $user = User::factory()->create();

    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Carteira Teste',
    ]);

    createDashboardCreditCardLiabilityGroup($wallet);

    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.001',
        name: 'Banco Principal',
    );
    $issuerBank = Bank::query()->create([
        'code' => '260',
        'name' => 'Nu Pagamentos S.A.',
        'short_name' => 'Nubank',
        'ispb' => '18236120',
        'active' => true,
    ]);
    $bankAccount->update(['bank_id' => $issuerBank->id]);

    $expenseAccount = AccountingTestHelper::account($wallet, '5.8.1', 'Despesa Administrativa', 'despesa', 'debit');
    $revenueAccount = AccountingTestHelper::account($wallet, '4.8.1', 'Receita de Serviços', 'receita', 'credit');
    $equityAccount = AccountingTestHelper::account($wallet, '3.1', 'Capital Social', 'patrimonio', 'credit');

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-01', [
        [$bankAccount->chartOfAccount, 'debit', 1000000],
        [$equityAccount, 'credit', 1000000],
    ]);

    AccountingTestHelper::createPostedEntry($wallet, '2026-07-05', [
        [$expenseAccount, 'debit', 10000],
        [$bankAccount->chartOfAccount, 'credit', 10000],
    ]);

    app(CreateAccountReceivable::class)->execute(
        $wallet,
        new AccountReceivableDTO(
            revenueAccountId: $revenueAccount->id,
            customerName: 'Cliente Alpha',
            description: 'Mensalidade Alpha',
            dueDate: '2026-07-20',
            amountCents: 20000,
        ),
    );

    app(CreateAccountPayable::class)->execute(
        $wallet,
        new AccountPayableDTO(
            expenseAccountId: $expenseAccount->id,
            payeeName: 'Fornecedor Beta',
            description: 'Serviço Beta',
            dueDate: '2026-07-22',
            amountCents: 15000,
        ),
    );

    $creditCard = app(CreateCreditCard::class)->execute(
        $wallet,
        new CreditCardDTO(
            name: 'Nubank Principal',
            issuerName: 'Nubank',
            network: 'mastercard',
            cardType: 'main',
            closingDay: 25,
            dueDay: 30,
            bestPurchaseDay: 26,
            creditLimitCents: 500000,
            bankId: $issuerBank->id,
        ),
    );

    app(CreateCreditCardTransaction::class)->execute(
        $wallet,
        new CreditCardTransactionDTO(
            creditCardId: $creditCard->id,
            expenseAccountId: $expenseAccount->id,
            purchaseDate: '2026-07-10',
            merchantName: 'Mercado Central',
            description: 'Compra no mercado',
            amountCents: 12000,
        ),
    );

    $dashboard = app(BuildFinancialDashboard::class)->handle(
        $wallet,
        new DashboardFiltersDTO(
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    );

    expect($dashboard['kpis']['cash_balance_cents'])->toBe(990000)
        ->and($dashboard['kpis']['realized_outflow_cents'])->toBe(10000)
        ->and($dashboard['kpis']['projected_inflow_cents'])->toBe(20000)
        ->and($dashboard['kpis']['projected_outflow_cents'])->toBe(27000)
        ->and($dashboard['kpis']['projected_net_cents'])->toBe(-7000)
        ->and($dashboard['kpis']['projected_cash_balance_cents'])->toBe(983000)
        ->and($dashboard['bankBalances'])->toHaveCount(1)
        ->and($dashboard['upcoming'])->toHaveCount(3);
});
