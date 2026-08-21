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
use App\Services\Financial\BuildRecurringFinancialForecastBacktest;
use App\Services\Financial\BuildRecurringFinancialRulesOverview;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\EstimateRecurringFinancialExpectationAmount;
use App\Services\Financial\ListRecurringFinancialExpectationsForRange;
use App\Services\Financial\ReviseRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

function forecastStrategyContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = AccountingTestHelper::account($wallet, '5.9.995', 'Despesa strategy', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.995', 'Receita strategy', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor Strategy', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Strategy', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function forecastStrategyRule(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';
    $data = array_merge([
        'type' => $type, 'description' => 'Energia Strategy', 'frequency' => 'monthly', 'dueDay' => 10,
        'amountMode' => 'variable', 'expectedAmountCents' => null, 'forecastStrategy' => null,
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2026-01-01', 'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides);

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...$data));
}

function forecastStrategyOccurrence(RecurringFinancialExpectation $rule, string $period, ?int $actual, string $status = 'confirmed'): RecurringFinancialOccurrence
{
    return RecurringFinancialOccurrence::query()->create([
        'wallet_id' => $rule->wallet_id, 'recurring_financial_expectation_id' => $rule->id,
        'period_date' => $period, 'due_date' => substr($period, 0, 8).'10', 'status' => $status,
        'expected_amount_cents' => 777777, 'actual_amount_cents' => $actual,
    ]);
}

it('adds the nullable strategy schema and normalizes creation invariants', function () {
    $context = forecastStrategyContext();
    $default = forecastStrategyRule($context);
    $last = forecastStrategyRule($context, ['description' => 'Last', 'forecastStrategy' => 'last_actual']);
    $median = forecastStrategyRule($context, ['description' => 'Median', 'forecastStrategy' => 'median_last_3']);
    $fixed = forecastStrategyRule($context, ['description' => 'Fixed', 'amountMode' => 'fixed', 'expectedAmountCents' => 10000, 'forecastStrategy' => 'median_last_3']);

    expect(Schema::hasColumn('recurring_financial_expectations', 'forecast_strategy'))->toBeTrue()
        ->and($default->forecast_strategy)->toBe('mean_last_3')
        ->and($last->forecast_strategy)->toBe('last_actual')
        ->and($median->forecast_strategy)->toBe('median_last_3')
        ->and($fixed->forecast_strategy)->toBeNull()
        ->and($fixed->effectiveForecastStrategy())->toBeNull();

    expect(fn () => forecastStrategyRule($context, ['description' => 'Invalid', 'forecastStrategy' => 'weighted']))
        ->toThrow(ValidationException::class);
});

it('defensively treats a legacy variable null as mean last three', function () {
    $context = forecastStrategyContext();
    $rule = forecastStrategyRule($context);
    $rule->forceFill(['forecast_strategy' => null])->save();

    expect($rule->fresh()->effectiveForecastStrategy())->toBe('mean_last_3')
        ->and($rule->fresh()->forecastStrategyLabel())->toBe('Média das últimas 3');
});

it('implements mean last actual and median with one two and three history items', function () {
    $context = forecastStrategyContext();
    $estimate = app(EstimateRecurringFinancialExpectationAmount::class);

    foreach (['mean_last_3', 'last_actual', 'median_last_3'] as $strategy) {
        $rule = forecastStrategyRule($context, ['description' => $strategy, 'forecastStrategy' => $strategy]);
        forecastStrategyOccurrence($rule, '2026-01-01', 10000);
        expect($estimate->execute($rule, CarbonImmutable::parse('2026-02-01')))->toBe(10000);
        forecastStrategyOccurrence($rule, '2026-02-01', 12001);
        expect($estimate->execute($rule, CarbonImmutable::parse('2026-03-01')))->toBe(match ($strategy) {
            'last_actual' => 12001, default => 11001,
        });
        forecastStrategyOccurrence($rule, '2026-03-01', 30000);
        expect($estimate->execute($rule, CarbonImmutable::parse('2026-04-01')))->toBe(match ($strategy) {
            'mean_last_3' => 17334, 'last_actual' => 30000, 'median_last_3' => 12001,
        });
    }
});

it('uses current-version fallback and ignores skipped future and insertion order', function () {
    $context = forecastStrategyContext();
    $estimate = app(EstimateRecurringFinancialExpectationAmount::class);
    foreach (RecurringFinancialExpectation::FORECAST_STRATEGIES as $strategy => $label) {
        $fallback = forecastStrategyRule($context, ['description' => 'Fallback '.$strategy, 'expectedAmountCents' => 20000, 'forecastStrategy' => $strategy]);
        $empty = forecastStrategyRule($context, ['description' => 'Empty '.$strategy, 'forecastStrategy' => $strategy]);
        expect($estimate->execute($fallback, CarbonImmutable::parse('2026-04-01')))->toBe(20000)
            ->and($estimate->execute($empty, CarbonImmutable::parse('2026-04-01')))->toBeNull();
    }

    $last = forecastStrategyRule($context, ['description' => 'Order', 'forecastStrategy' => 'last_actual']);
    forecastStrategyOccurrence($last, '2026-03-01', 30000);
    forecastStrategyOccurrence($last, '2026-01-01', 10000);
    forecastStrategyOccurrence($last, '2026-04-01', 999999, 'skipped');
    forecastStrategyOccurrence($last, '2026-02-01', 12000);
    forecastStrategyOccurrence($last, '2026-06-01', 800000);

    expect($estimate->execute($last, CarbonImmutable::parse('2026-05-01')))->toBe(30000);
});

it('versions strategy transitions without resetting the schedule anchor or creating records', function () {
    CarbonImmutable::setTestNow('2026-08-21');
    $context = forecastStrategyContext();
    $v1 = forecastStrategyRule($context, ['frequency' => 'quarterly', 'forecastStrategy' => 'mean_last_3']);
    $before = [RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($context['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), new RecurringFinancialExpectationDTO(
        type: 'payable', description: 'Strategy V2', frequency: 'quarterly', dueDay: 10, amountMode: 'variable',
        expectedAmountCents: null, defaultAccountId: $context['expense']->id, startsOn: '2026-09-01',
        supplierId: $context['supplier']->id, forecastStrategy: 'median_last_3',
    ));

    expect($v1->fresh()->forecast_strategy)->toBe('mean_last_3')
        ->and($v1->fresh()->ends_on->toDateString())->toBe('2026-08-31')
        ->and($v2->forecast_strategy)->toBe('median_last_3')
        ->and($v2->starts_on->toDateString())->toBe('2026-09-01')
        ->and($v2->schedule_anchor_date->toDateString())->toBe('2026-01-01')
        ->and($v2->isApplicableTo(CarbonImmutable::parse('2026-09-01')))->toBeFalse()
        ->and($v2->isApplicableTo(CarbonImmutable::parse('2026-10-01')))->toBeTrue()
        ->and([RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});

it('normalizes variable fixed transitions and carries real history across versions', function () {
    CarbonImmutable::setTestNow('2026-08-21');
    $context = forecastStrategyContext();
    $variable = forecastStrategyRule($context, ['forecastStrategy' => 'median_last_3']);
    foreach ([10000, 30000, 12000] as $month => $amount) {
        forecastStrategyOccurrence($variable, sprintf('2026-%02d-01', $month + 1), $amount);
    }
    $fixed = app(ReviseRecurringFinancialExpectation::class)->execute($context['wallet'], $variable, CarbonImmutable::parse('2026-09-01'), new RecurringFinancialExpectationDTO(
        type: 'payable', description: 'Fixed', frequency: 'monthly', dueDay: 10, amountMode: 'fixed', expectedAmountCents: 50000,
        defaultAccountId: $context['expense']->id, startsOn: '2026-09-01', supplierId: $context['supplier']->id, forecastStrategy: 'median_last_3',
    ));
    $variableAgain = app(ReviseRecurringFinancialExpectation::class)->execute($context['wallet'], $fixed, CarbonImmutable::parse('2026-10-01'), new RecurringFinancialExpectationDTO(
        type: 'payable', description: 'Variable again', frequency: 'monthly', dueDay: 10, amountMode: 'variable', expectedAmountCents: null,
        defaultAccountId: $context['expense']->id, startsOn: '2026-10-01', supplierId: $context['supplier']->id,
    ));

    expect($variable->fresh()->forecast_strategy)->toBe('median_last_3')
        ->and($fixed->forecast_strategy)->toBeNull()
        ->and($variableAgain->forecast_strategy)->toBe('mean_last_3')
        ->and(app(EstimateRecurringFinancialExpectationAmount::class)->execute($variableAgain, CarbonImmutable::parse('2026-10-01')))->toBe(17333);
});

it('stores an immutable confirmation snapshot produced by the configured strategy', function () {
    $context = forecastStrategyContext();
    $rule = forecastStrategyRule($context, ['forecastStrategy' => 'median_last_3']);
    foreach ([10000, 30000, 12000] as $month => $amount) {
        forecastStrategyOccurrence($rule, sprintf('2026-%02d-01', $month + 1), $amount);
    }

    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute($context['wallet'], $rule, CarbonImmutable::parse('2026-04-01'), 12790);
    $rule->update(['forecast_strategy' => 'last_actual']);

    expect($occurrence->fresh()->expected_amount_cents)->toBe(12000)
        ->and($occurrence->fresh()->actual_amount_cents)->toBe(12790);
});

it('reports current strategy dynamically and keeps recommendations analytical', function () {
    $context = forecastStrategyContext();
    $tie = forecastStrategyRule($context, ['forecastStrategy' => 'median_last_3']);
    foreach (range(1, 5) as $month) {
        forecastStrategyOccurrence($tie, sprintf('2026-%02d-01', $month), 10000);
    }
    $different = forecastStrategyRule($context, ['description' => 'Recommendation differs', 'forecastStrategy' => 'mean_last_3']);
    foreach ([10000, 10000, 100000, 10000, 10000] as $month => $amount) {
        forecastStrategyOccurrence($different, sprintf('2026-%02d-01', $month + 1), $amount);
    }
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];

    $tieBacktest = app(BuildRecurringFinancialForecastBacktest::class)->execute($context['wallet'], $tie);
    $differentBacktest = app(BuildRecurringFinancialForecastBacktest::class)->execute($context['wallet'], $different);
    $overview = app(BuildRecurringFinancialRulesOverview::class)->execute($context['wallet'], 'payable', now());

    expect($tieBacktest['current_strategy'])->toBe('median_last_3')
        ->and($tieBacktest['recommended_strategy'])->toBe('median_last_3')
        ->and($differentBacktest['current_strategy'])->toBe('mean_last_3')
        ->and($differentBacktest['recommended_strategy'])->toBe('median_last_3')
        ->and($different->fresh()->forecast_strategy)->toBe('mean_last_3')
        ->and($overview[0])->toHaveKeys(['forecast_strategy', 'forecast_strategy_label', 'performance', 'backtest'])
        ->and([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});

it('feeds range and cash flow through the canonical configured estimator', function () {
    $context = forecastStrategyContext();
    $rule = forecastStrategyRule($context, ['forecastStrategy' => 'median_last_3']);
    foreach ([10000, 30000, 12000] as $month => $amount) {
        forecastStrategyOccurrence($rule, sprintf('2026-%02d-01', $month + 1), $amount);
    }

    $range = app(ListRecurringFinancialExpectationsForRange::class)->execute($context['wallet'], 'payable', CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-30'));
    $cashFlow = app(BuildCashFlow::class)->handle($context['wallet'], new CashFlowFiltersDTO('2026-04-01', '2026-04-30'));
    $cashItem = collect($cashFlow['items'])->firstWhere('source', 'recurring_payable');

    expect($range[0]['expected_amount_cents'])->toBe(12000)
        ->and($cashItem['amount_cents'])->toBe(-12000);
});
