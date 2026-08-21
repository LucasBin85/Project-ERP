<?php

use App\DTOs\Financial\CashFlowFiltersDTO;
use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\BuildCashFlow;
use App\Services\Financial\BuildManagerialFinancialDashboard;
use App\Services\Financial\BuildMonthlyWalletClosingSummary;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\SkipRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function recurringDashboardContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.995', 'Banco projeção gerencial');
    $expense = AccountingTestHelper::account($wallet, '5.9.995', 'Despesa projeção gerencial', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.995', 'Receita projeção gerencial', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor Dashboard', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Dashboard', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'bank', 'expense', 'revenue', 'supplier', 'customer');
}

function recurringDashboardExpectation(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';
    $data = array_merge([
        'type' => $type,
        'description' => $type === 'payable' ? 'Internet Dashboard' : 'Mensalidade Dashboard',
        'frequency' => 'monthly', 'dueDay' => 10, 'amountMode' => 'fixed', 'expectedAmountCents' => 150000,
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2026-01-01',
        'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides);

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...$data));
}

function recurringDashboard(array $context, int $month = 9): array
{
    return app(BuildManagerialFinancialDashboard::class)->execute($context['wallet'], 2026, $month);
}

it('projects fixed payable and receivable without changing AP AR open cards', function () {
    $context = recurringDashboardContext();
    recurringDashboardExpectation($context);
    recurringDashboardExpectation($context, ['type' => 'receivable', 'expectedAmountCents' => 200000]);

    $dashboard = recurringDashboard($context);

    expect($dashboard['cash_projection']['projected_outflows_cents'])->toBe(150000)
        ->and($dashboard['cash_projection']['projected_inflows_cents'])->toBe(200000)
        ->and($dashboard['cash_projection']['projected_net_cents'])->toBe(50000)
        ->and($dashboard['cash_projection']['projected_closing_balance_cents'])->toBe(50000)
        ->and($dashboard['cash_projection']['is_complete'])->toBeTrue()
        ->and($dashboard['cards']['payables_open_cents'])->toBe(0)
        ->and($dashboard['cards']['receivables_open_cents'])->toBe(0);
});

it('uses canonical variable history and represents unknown projections as partial', function () {
    $context = recurringDashboardContext();
    $history = recurringDashboardExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => null]);
    foreach ([18400, 21300, 19600] as $index => $amount) {
        RecurringFinancialOccurrence::query()->create([
            'wallet_id' => $context['wallet']->id, 'recurring_financial_expectation_id' => $history->id,
            'period_date' => sprintf('2026-%02d-01', $index + 6), 'due_date' => sprintf('2026-%02d-10', $index + 6),
            'status' => 'confirmed', 'actual_amount_cents' => $amount,
        ]);
    }
    recurringDashboardExpectation($context, ['description' => 'Energia desconhecida', 'amountMode' => 'variable', 'expectedAmountCents' => null]);

    $projection = recurringDashboard($context)['cash_projection'];

    expect($projection['projected_outflows_cents'])->toBe(19767)
        ->and($projection['projected_closing_balance_cents'])->toBe(-19767)
        ->and($projection['unestimated_projected_outflows_count'])->toBe(1)
        ->and($projection['unestimated_projected_items_count'])->toBe(1)
        ->and($projection['is_complete'])->toBeFalse();
});

it('uses only known values when known and unknown projections coexist', function () {
    $context = recurringDashboardContext();
    recurringDashboardExpectation($context, ['expectedAmountCents' => 50000]);
    recurringDashboardExpectation($context, ['description' => 'Sem estimativa', 'amountMode' => 'variable', 'expectedAmountCents' => null]);

    $projection = recurringDashboard($context)['cash_projection'];

    expect($projection['projected_outflows_cents'])->toBe(50000)
        ->and($projection['projected_closing_balance_cents'])->toBe(-50000)
        ->and($projection['is_complete'])->toBeFalse();
});

it('replaces a virtual estimate with the confirmed actual title without double counting', function () {
    $context = recurringDashboardContext();
    $expectation = recurringDashboardExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => 12000]);
    expect(recurringDashboard($context)['cash_projection']['projected_outflows_cents'])->toBe(12000);

    app(ConfirmRecurringFinancialExpectation::class)->execute($context['wallet'], $expectation, CarbonImmutable::parse('2026-09-01'), 12790);
    $dashboard = recurringDashboard($context);

    expect($dashboard['cash_projection']['projected_outflows_cents'])->toBe(12790)
        ->and($dashboard['cards']['payables_open_cents'])->toBe(12790)
        ->and(AccountPayable::count())->toBe(1);
});

it('excludes skipped foreign and out-of-month expectations', function () {
    $context = recurringDashboardContext();
    $skipped = recurringDashboardExpectation($context);
    app(SkipRecurringFinancialExpectation::class)->execute($context['wallet'], $skipped, CarbonImmutable::parse('2026-09-01'));
    recurringDashboardExpectation($context, ['description' => 'Somente outubro', 'startsOn' => '2026-10-01']);
    $foreign = recurringDashboardContext();
    recurringDashboardExpectation($foreign);

    expect(recurringDashboard($context, 9)['cash_projection']['projected_outflows_cents'])->toBe(0)
        ->and(recurringDashboard($context, 10)['cash_projection']['projected_outflows_cents'])->toBe(300000);
});

it('respects V1 and V2 validity in their respective dashboard months', function () {
    $context = recurringDashboardContext();
    $v1 = recurringDashboardExpectation($context, ['description' => 'Regra V1', 'expectedAmountCents' => 10000]);
    $v1->update(['ends_on' => '2026-09-30']);
    recurringDashboardExpectation($context, ['description' => 'Regra V2', 'expectedAmountCents' => 12000, 'startsOn' => '2026-10-01'])
        ->update(['replaces_expectation_id' => $v1->id]);

    expect(recurringDashboard($context, 9)['cash_projection']['projected_outflows_cents'])->toBe(10000)
        ->and(recurringDashboard($context, 10)['cash_projection']['projected_outflows_cents'])->toBe(12000);
});

it('matches BuildCashFlow summary exactly and exposes the monthly all-mode URL', function () {
    $context = recurringDashboardContext();
    recurringDashboardExpectation($context, ['type' => 'receivable', 'expectedAmountCents' => 33000]);
    $dashboard = recurringDashboard($context);
    $cashFlow = app(BuildCashFlow::class)->handle($context['wallet'], new CashFlowFiltersDTO('2026-09-01', '2026-09-30', 'all', ''));
    $fields = [
        'opening_balance_cents', 'realized_inflows_cents', 'realized_outflows_cents',
        'projected_inflows_cents', 'projected_outflows_cents', 'projected_net_cents',
        'projected_closing_balance_cents', 'unestimated_projected_inflows_count',
        'unestimated_projected_outflows_count', 'unestimated_projected_items_count',
    ];

    foreach ($fields as $field) {
        expect($dashboard['cash_projection'][$field])->toBe($cashFlow['summary'][$field]);
    }
    expect($dashboard['cash_projection']['url'])->toBe(route('cash-flow.index', [
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'mode' => 'all',
    ]));
});

it('keeps dashboard and monthly closing builds read only and uncoupled from virtual expectations', function () {
    $context = recurringDashboardContext();
    recurringDashboardExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => null]);
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    $closingBefore = app(BuildMonthlyWalletClosingSummary::class)->execute($context['wallet'], 2026, 9);

    recurringDashboard($context);
    $closingAfter = app(BuildMonthlyWalletClosingSummary::class)->execute($context['wallet'], 2026, 9);

    expect([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before)
        ->and($closingAfter['status'])->toBe($closingBefore['status'])
        ->and($closingAfter['closing_blockers'])->toBe($closingBefore['closing_blockers'])
        ->and($closingAfter['payables'])->toBe($closingBefore['payables'])
        ->and($closingAfter['receivables'])->toBe($closingBefore['receivables']);
});

it('delivers cash projection through the existing dashboard inertia response', function () {
    $context = recurringDashboardContext();
    recurringDashboardExpectation($context, ['expectedAmountCents' => 42000]);

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('dashboard', ['year' => 2026, 'month' => 9]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.cash_projection.projected_outflows_cents', 42000)
            ->where('dashboard.cash_projection.is_complete', true)
            ->where('dashboard.cash_projection.url', route('cash-flow.index', [
                'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'mode' => 'all',
            ])));
});
