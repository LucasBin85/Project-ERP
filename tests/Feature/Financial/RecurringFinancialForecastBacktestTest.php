<?php

use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\BuildRecurringFinancialForecastBacktest;
use App\Services\Financial\BuildRecurringFinancialRulesOverview;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

function backtestContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = AccountingTestHelper::account($wallet, '5.9.996', 'Despesa backtest', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.996', 'Receita backtest', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor Backtest', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Backtest', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function backtestRule(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';
    $data = array_merge([
        'type' => $type, 'description' => 'Energia Backtest', 'frequency' => 'monthly', 'dueDay' => 10,
        'amountMode' => 'variable', 'expectedAmountCents' => null,
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2025-01-01', 'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides);

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...$data));
}

function backtestOccurrence(RecurringFinancialExpectation $rule, string $period, ?int $actual, array $overrides = []): RecurringFinancialOccurrence
{
    return RecurringFinancialOccurrence::query()->create(array_merge([
        'wallet_id' => $rule->wallet_id, 'recurring_financial_expectation_id' => $rule->id,
        'period_date' => $period, 'due_date' => substr($period, 0, 8).'10', 'status' => 'confirmed',
        'expected_amount_cents' => 999999, 'actual_amount_cents' => $actual,
    ], $overrides));
}

function backtestSeries(RecurringFinancialExpectation $rule, array $actuals, string $start = '2025-01-01'): void
{
    foreach ($actuals as $index => $actual) {
        backtestOccurrence($rule, Carbon\CarbonImmutable::parse($start)->addMonths($index)->toDateString(), $actual);
    }
}

function buildBacktest(array $context, RecurringFinancialExpectation $rule): array
{
    return app(BuildRecurringFinancialForecastBacktest::class)->execute($context['wallet'], $rule);
}

function strategy(array $backtest, string $code): array
{
    return collect($backtest['strategies'])->firstWhere('code', $code);
}

it('calculates all strategies sequentially without look ahead and with a rolling window', function () {
    $context = backtestContext();
    $rule = backtestRule($context);
    backtestSeries($rule, [10000, 30000, 12000, 15000, 1000000]);

    $backtest = buildBacktest($context, $rule);
    $mean = strategy($backtest, 'mean_last_3');
    $last = strategy($backtest, 'last_actual');
    $median = strategy($backtest, 'median_last_3');

    expect($mean['periods'][0]['forecast_amount_cents'])->toBe(17333)
        ->and($last['periods'][0]['forecast_amount_cents'])->toBe(12000)
        ->and($median['periods'][0]['forecast_amount_cents'])->toBe(12000)
        ->and($mean['periods'][0]['forecast_amount_cents'])->not->toBe(340667)
        ->and($mean['periods'][1]['forecast_amount_cents'])->toBe(19000)
        ->and($last['periods'][1]['forecast_amount_cents'])->toBe(15000)
        ->and($median['periods'][1]['forecast_amount_cents'])->toBe(15000)
        ->and($mean['periods'][0]['variance_cents'])->toBe(-2333)
        ->and($mean['periods'][0]['variance_bps'])->toBe(-1346)
        ->and($mean['mean_absolute_variance_cents'])->toBe(491667)
        ->and($mean['mean_signed_variance_cents'])->toBe(489334)
        ->and($mean['mean_absolute_percentage_error_bps'])->toBe(258831)
        ->and(array_unique(array_column($backtest['strategies'], 'sample_count')))->toBe([2]);
});

it('orders by financial period and ignores skipped actual-null and expected snapshots', function () {
    $context = backtestContext();
    $rule = backtestRule($context);
    backtestOccurrence($rule, '2025-03-01', 12000, ['expected_amount_cents' => 1]);
    backtestOccurrence($rule, '2025-01-01', 10000, ['expected_amount_cents' => 900000]);
    backtestOccurrence($rule, '2025-02-01', 11000, ['expected_amount_cents' => 800000]);
    backtestOccurrence($rule, '2025-04-01', 999999, ['status' => 'skipped']);
    backtestOccurrence($rule, '2025-05-01', null);
    backtestOccurrence($rule, '2025-06-01', 15000);

    $backtest = buildBacktest($context, $rule);

    expect($backtest['total_eligible_actual_count'])->toBe(4)
        ->and($backtest['backtest_target_count'])->toBe(1)
        ->and($backtest['sample_target_count'])->toBe(1)
        ->and(strategy($backtest, 'mean_last_3')['periods'][0])->toMatchArray([
            'period_date' => '2025-06-01', 'forecast_amount_cents' => 11000, 'actual_amount_cents' => 15000,
        ]);
});

it('keeps the latest twelve comparable targets after the warm up', function () {
    $context = backtestContext();
    $rule = backtestRule($context);
    backtestSeries($rule, range(10000, 25000, 1000));

    $backtest = buildBacktest($context, $rule);

    expect($backtest['total_eligible_actual_count'])->toBe(16)
        ->and($backtest['backtest_target_count'])->toBe(13)
        ->and($backtest['sample_target_count'])->toBe(12)
        ->and(strategy($backtest, 'mean_last_3')['periods'])->toHaveCount(12)
        ->and(strategy($backtest, 'mean_last_3')['periods'][0]['period_date'])->toBe('2025-05-01')
        ->and(array_unique(array_column($backtest['strategies'], 'sample_count')))->toBe([12]);
});

it('returns explicit fixed and insufficient-history states without zero metrics', function () {
    $context = backtestContext();
    $fixed = backtestRule($context, ['amountMode' => 'fixed', 'expectedAmountCents' => 10000]);
    backtestSeries($fixed, [10000, 10000, 10000, 10000]);
    $variable = backtestRule($context, ['description' => 'Pouco histórico']);
    backtestSeries($variable, [10000, 11000, 12000]);

    expect(buildBacktest($context, $fixed))->toMatchArray([
        'applicable' => false, 'reason' => 'fixed_amount', 'recommended_strategy' => null, 'strategies' => [],
    ])->and(buildBacktest($context, $variable))->toMatchArray([
        'applicable' => true, 'reason' => 'insufficient_history', 'has_sufficient_history' => false,
        'total_eligible_actual_count' => 3, 'sample_target_count' => 0, 'recommended_strategy' => null, 'strategies' => [],
    ]);
});

it('continues variable history across V1 V2 V3 and excludes a fixed version boundary', function () {
    $context = backtestContext();
    $v1 = backtestRule($context, ['description' => 'V1']);
    backtestSeries($v1, [10000, 11000, 12000]);
    $v2 = backtestRule($context, ['description' => 'V2 fixa', 'amountMode' => 'fixed', 'expectedAmountCents' => 900000, 'startsOn' => '2025-04-01']);
    $v2->update(['replaces_expectation_id' => $v1->id]);
    backtestOccurrence($v2, '2025-04-01', 900000);
    $v3 = backtestRule($context, ['description' => 'V3', 'startsOn' => '2025-05-01']);
    $v3->update(['replaces_expectation_id' => $v2->id]);
    backtestOccurrence($v3, '2025-05-01', 15000);

    $backtest = buildBacktest($context, $v3);

    expect($backtest['total_eligible_actual_count'])->toBe(4)
        ->and(strategy($backtest, 'mean_last_3')['periods'][0]['forecast_amount_cents'])->toBe(11000)
        ->and(strategy($backtest, 'mean_last_3')['periods'][0]['period_date'])->toBe('2025-05-01');
});

it('recommends median by MAE and prefers the current method on a total tie', function () {
    $context = backtestContext();
    $medianRule = backtestRule($context, ['description' => 'Mediana vence']);
    backtestSeries($medianRule, [10000, 10000, 100000, 10000, 10000]);
    $tieRule = backtestRule($context, ['description' => 'Empate']);
    backtestSeries($tieRule, [10000, 10000, 10000, 10000, 10000]);

    $median = buildBacktest($context, $medianRule);
    $tie = buildBacktest($context, $tieRule);

    expect($median['recommended_strategy'])->toBe('median_last_3')
        ->and(strategy($median, 'median_last_3')['mean_absolute_variance_cents'])->toBe(0)
        ->and($tie['recommended_strategy'])->toBe('mean_last_3')
        ->and($tie['recommended_strategy_label'])->toBe('Média das últimas 3');
});

it('keeps variance neutral for payable and receivable and remains read only', function (string $type) {
    $context = backtestContext();
    $rule = backtestRule($context, ['type' => $type]);
    backtestSeries($rule, [10000, 10000, 10000, 12000]);
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];

    $backtest = buildBacktest($context, $rule);

    expect(strategy($backtest, 'mean_last_3')['periods'][0]['variance_cents'])->toBe(2000)
        ->and([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
})->with(['payable', 'receivable']);

it('integrates backtest beside performance in overview and both inertia indexes', function () {
    $context = backtestContext();
    $payable = backtestRule($context);
    $receivable = backtestRule($context, ['type' => 'receivable']);
    backtestSeries($payable, [10000, 10000, 10000, 12000]);
    backtestSeries($receivable, [20000, 20000, 20000, 18000]);
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];

    $overview = app(BuildRecurringFinancialRulesOverview::class)->execute($context['wallet'], 'payable', now());
    expect($overview[0])->toHaveKeys(['performance', 'backtest'])
        ->and($overview[0]['backtest']['sample_target_count'])->toBe(1)
        ->and([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);

    $session = ['active_wallet' => $context['wallet']->id];
    $this->actingAs($context['user'])->withSession($session)
        ->get(route('accounts-payable.index', ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('recurringRules.0.backtest.sample_target_count', 1));
    $this->get(route('accounts-receivable.index', ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('recurringRules.0.backtest.sample_target_count', 1));
});
