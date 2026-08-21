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
use App\Services\Financial\BuildMonthlyWalletClosingSummary;
use App\Services\Financial\BuildRecurringFinancialForecastBacktest;
use App\Services\Financial\BuildRecurringFinancialRulePerformance;
use App\Services\Financial\BuildRecurringFinancialRulesOverview;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\ListRecurringFinancialExpectationsForRange;
use App\Services\Financial\ReviseRecurringFinancialExpectation;
use App\Services\Financial\SkipRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

function lifecycleAcceptanceContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = AccountingTestHelper::account($wallet, '5.9.994', 'Despesa acceptance', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.994', 'Receita acceptance', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Internet Acceptance', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Acceptance', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function lifecycleAcceptanceRule(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...array_merge([
        'type' => $type, 'description' => 'Internet', 'frequency' => 'monthly', 'dueDay' => 10,
        'amountMode' => 'variable', 'expectedAmountCents' => null, 'forecastStrategy' => 'mean_last_3',
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2026-01-01', 'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides)));
}

function lifecycleAcceptanceOccurrence(RecurringFinancialExpectation $rule, string $period, int $actual): void
{
    RecurringFinancialOccurrence::query()->create([
        'wallet_id' => $rule->wallet_id, 'recurring_financial_expectation_id' => $rule->id,
        'period_date' => $period, 'due_date' => substr($period, 0, 8).'10', 'status' => 'confirmed',
        'expected_amount_cents' => $actual, 'actual_amount_cents' => $actual,
    ]);
}

it('accepts a complete variable lifecycle through versioning forecast confirmation and analytics', function () {
    CarbonImmutable::setTestNow('2026-08-21');
    $context = lifecycleAcceptanceContext();
    $v1 = lifecycleAcceptanceRule($context, ['expectedAmountCents' => 20000]);
    lifecycleAcceptanceOccurrence($v1, '2026-06-01', 10000);
    lifecycleAcceptanceOccurrence($v1, '2026-07-01', 30000);
    lifecycleAcceptanceOccurrence($v1, '2026-08-01', 12000);
    $beforeRevision = [AccountPayable::count(), RecurringFinancialOccurrence::count(), JournalEntry::count()];

    $v2 = app(ReviseRecurringFinancialExpectation::class)->execute($context['wallet'], $v1, CarbonImmutable::parse('2026-09-01'), new RecurringFinancialExpectationDTO(
        type: 'payable', description: 'Internet revisada', frequency: 'monthly', dueDay: 10,
        amountMode: 'variable', expectedAmountCents: 20000, defaultAccountId: $context['expense']->id,
        startsOn: '2026-09-01', supplierId: $context['supplier']->id, forecastStrategy: 'median_last_3',
    ));
    $afterRevision = [AccountPayable::count(), RecurringFinancialOccurrence::count(), JournalEntry::count()];
    $virtual = app(ListRecurringFinancialExpectationsForRange::class)->execute($context['wallet'], 'payable', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    $occurrence = app(ConfirmRecurringFinancialExpectation::class)->execute($context['wallet'], $v2, CarbonImmutable::parse('2026-09-01'), 12790);
    $rangeAfter = app(ListRecurringFinancialExpectationsForRange::class)->execute($context['wallet'], 'payable', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    $cashFlow = app(BuildCashFlow::class)->handle($context['wallet'], new CashFlowFiltersDTO('2026-09-01', '2026-09-30'));
    $performance = app(BuildRecurringFinancialRulePerformance::class)->execute($context['wallet'], $v2);
    $backtest = app(BuildRecurringFinancialForecastBacktest::class)->execute($context['wallet'], $v2);

    expect($v1->fresh()->ends_on->toDateString())->toBe('2026-08-31')
        ->and($v2->replaces_expectation_id)->toBe($v1->id)
        ->and($v2->schedule_anchor_date->toDateString())->toBe($v1->schedule_anchor_date->toDateString())
        ->and($v2->forecast_strategy)->toBe('median_last_3')
        ->and($afterRevision)->toBe($beforeRevision)
        ->and($virtual)->toHaveCount(1)
        ->and($virtual[0]['expected_amount_cents'])->toBe(12000)
        ->and($occurrence->expected_amount_cents)->toBe(12000)
        ->and($occurrence->actual_amount_cents)->toBe(12790)
        ->and($rangeAfter)->toBe([])
        ->and(collect($cashFlow['items'])->where('source', 'recurring_payable'))->toBeEmpty()
        ->and(collect($cashFlow['items'])->where('source', 'accounts_payable')->first()['amount_cents'])->toBe(-12790)
        ->and($performance['periods'][0]['expected_amount_cents'])->toBe(12000)
        ->and($backtest['current_strategy'])->toBe('median_last_3');
});

it('accepts skip unknown fixed and receivable paths without materializing or double counting', function () {
    $context = lifecycleAcceptanceContext();
    $unknown = lifecycleAcceptanceRule($context, ['description' => 'Sem estimativa']);
    $unknownRange = app(ListRecurringFinancialExpectationsForRange::class)->execute($context['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
    $unknownCash = app(BuildCashFlow::class)->handle($context['wallet'], new CashFlowFiltersDTO('2026-08-01', '2026-08-31'));
    $unknownClosing = app(BuildMonthlyWalletClosingSummary::class)->execute($context['wallet'], 2026, 8);
    $beforeSkip = [AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    app(SkipRecurringFinancialExpectation::class)->execute($context['wallet'], $unknown, CarbonImmutable::parse('2026-08-01'));
    $afterSkip = [AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];

    $fixed = lifecycleAcceptanceRule($context, ['description' => 'Aluguel', 'amountMode' => 'fixed', 'expectedAmountCents' => 50000, 'forecastStrategy' => null]);
    $fixedOccurrence = app(ConfirmRecurringFinancialExpectation::class)->execute($context['wallet'], $fixed, CarbonImmutable::parse('2026-08-01'), 50000);
    $receivable = lifecycleAcceptanceRule($context, ['type' => 'receivable', 'description' => 'Mensalidade', 'expectedAmountCents' => 15000]);
    $receivableOccurrence = app(ConfirmRecurringFinancialExpectation::class)->execute($context['wallet'], $receivable, CarbonImmutable::parse('2026-08-01'), 17000);

    expect($unknownRange[0]['expected_amount_cents'])->toBeNull()
        ->and($unknownCash['summary']['unestimated_projected_outflows_count'])->toBe(1)
        ->and($unknownClosing['recurring_review']['payables']['unestimated_count'])->toBe(1)
        ->and($afterSkip)->toBe($beforeSkip)
        ->and(app(ListRecurringFinancialExpectationsForRange::class)->execute($context['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')))->not->toContain($unknownRange[0])
        ->and(app(ListRecurringFinancialExpectationsForRange::class)->execute($context['wallet'], 'payable', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')))->not->toBeEmpty()
        ->and($fixed->forecast_strategy)->toBeNull()
        ->and($fixedOccurrence->expected_amount_cents)->toBe(50000)
        ->and(app(BuildRecurringFinancialForecastBacktest::class)->execute($context['wallet'], $fixed)['applicable'])->toBeFalse()
        ->and(app(BuildRecurringFinancialRulePerformance::class)->execute($context['wallet'], $fixed)['mean_absolute_variance_cents'])->toBe(0)
        ->and($receivableOccurrence->account_receivable_id)->not->toBeNull()
        ->and($receivableOccurrence->expected_amount_cents)->toBe(15000)
        ->and(collect(app(BuildCashFlow::class)->handle($context['wallet'], new CashFlowFiltersDTO('2026-08-01', '2026-08-31'))['items'])->where('source', 'recurring_receivable'))->toBeEmpty();
});

it('keeps overview reads bounded and read only for long resolved histories', function () {
    $context = lifecycleAcceptanceContext();
    $rule = lifecycleAcceptanceRule($context);
    foreach (range(0, 23) as $offset) {
        lifecycleAcceptanceOccurrence($rule, CarbonImmutable::parse('2024-09-01')->addMonths($offset)->toDateString(), 10000);
    }
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    DB::flushQueryLog();
    DB::enableQueryLog();

    $overview = app(BuildRecurringFinancialRulesOverview::class)->execute($context['wallet'], 'payable', CarbonImmutable::parse('2026-08-01'));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($overview[0]['next_period_date'])->toBe('2026-09-01')
        ->and($queryCount)->toBeLessThan(20)
        ->and([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});
