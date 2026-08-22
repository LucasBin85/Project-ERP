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
use App\Services\Financial\BuildRecurringFinancialRulePerformance;
use App\Services\Financial\BuildRecurringFinancialRulesOverview;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

function performanceContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = AccountingTestHelper::account($wallet, '5.9.997', 'Despesa performance', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.997', 'Receita performance', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor Performance', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Performance', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function performanceRule(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';
    $data = array_merge([
        'type' => $type, 'description' => 'Energia Performance', 'frequency' => 'monthly', 'dueDay' => 10,
        'amountMode' => 'variable', 'expectedAmountCents' => null,
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2025-01-01', 'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides);

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...$data));
}

function performanceOccurrence(RecurringFinancialExpectation $rule, string $period, ?int $expected, ?int $actual, array $overrides = []): RecurringFinancialOccurrence
{
    return RecurringFinancialOccurrence::query()->create(array_merge([
        'wallet_id' => $rule->wallet_id, 'recurring_financial_expectation_id' => $rule->id,
        'period_date' => $period, 'due_date' => substr($period, 0, 8).'10', 'status' => 'confirmed',
        'expected_amount_cents' => $expected, 'actual_amount_cents' => $actual,
    ], $overrides));
}

function buildPerformance(array $context, RecurringFinancialExpectation $rule): array
{
    return app(BuildRecurringFinancialRulePerformance::class)->execute($context['wallet'], $rule);
}

it('uses immutable occurrence snapshots instead of recalculating historical forecasts', function () {
    $context = performanceContext();
    $v1 = performanceRule($context, ['expectedAmountCents' => 10000]);
    performanceOccurrence($v1, '2026-08-01', 10000, 12000);
    $v1->update(['ends_on' => '2026-08-31']);
    $v2 = performanceRule($context, ['expectedAmountCents' => 20000, 'startsOn' => '2026-09-01']);
    $v2->update(['replaces_expectation_id' => $v1->id]);

    $period = buildPerformance($context, $v2)['periods'][0];

    expect($period['expected_amount_cents'])->toBe(10000)
        ->and($period['actual_amount_cents'])->toBe(12000)
        ->and($period['variance_cents'])->toBe(2000);
});

it('calculates MAE bias and MAPE with neutral signed variance', function () {
    $context = performanceContext();
    $rule = performanceRule($context);
    performanceOccurrence($rule, '2026-06-01', 10000, 12000);
    performanceOccurrence($rule, '2026-07-01', 20000, 18000);
    performanceOccurrence($rule, '2026-08-01', 30000, 33000);

    $performance = buildPerformance($context, $rule);

    expect($performance['mean_absolute_variance_cents'])->toBe(2333)
        ->and($performance['mean_signed_variance_cents'])->toBe(1000)
        ->and($performance['mean_absolute_percentage_error_bps'])->toBe(1333)
        ->and($performance['periods'][0]['variance_cents'])->toBe(3000)
        ->and($performance['periods'][1]['variance_cents'])->toBe(-2000)
        ->and($performance['periods'][2]['variance_bps'])->toBe(2000);
});

it('rounds half up instead of truncating', function () {
    $context = performanceContext();
    $rule = performanceRule($context);
    performanceOccurrence($rule, '2026-07-01', 10000, 10001);
    performanceOccurrence($rule, '2026-08-01', 10000, 10000);

    expect(buildPerformance($context, $rule)['mean_absolute_variance_cents'])->toBe(1);
});

it('keeps unestimated and legacy actual-null confirmations in history but outside metrics', function () {
    $context = performanceContext();
    $rule = performanceRule($context);
    performanceOccurrence($rule, '2026-07-01', null, 20000);
    performanceOccurrence($rule, '2026-08-01', 10000, null);
    $performance = buildPerformance($context, $rule);

    expect($performance)->toMatchArray([
        'total_confirmed_count' => 2, 'sample_confirmed_count' => 2,
        'estimated_confirmed_count' => 0, 'unestimated_confirmed_count' => 2,
        'mean_absolute_variance_cents' => null, 'mean_signed_variance_cents' => null,
        'mean_absolute_percentage_error_bps' => null,
    ])->and($performance['periods'][1]['has_estimate'])->toBeFalse();
});

it('reports zero error naturally for fixed snapshots and counts skipped separately', function () {
    $context = performanceContext();
    $rule = performanceRule($context, ['amountMode' => 'fixed', 'expectedAmountCents' => 150000]);
    performanceOccurrence($rule, '2026-07-01', 150000, 150000);
    performanceOccurrence($rule, '2026-08-01', 150000, null, ['status' => 'skipped']);
    $performance = buildPerformance($context, $rule);

    expect($performance['sample_confirmed_count'])->toBe(1)->and($performance['skipped_total_count'])->toBe(1)
        ->and($performance['mean_absolute_variance_cents'])->toBe(0)
        ->and($performance['mean_signed_variance_cents'])->toBe(0)
        ->and($performance['mean_absolute_percentage_error_bps'])->toBe(0);
});

it('traverses the full V1 V2 V3 chain as one history', function () {
    $context = performanceContext();
    $v1 = performanceRule($context, ['description' => 'V1']);
    performanceOccurrence($v1, '2026-06-01', 10000, 11000);
    $v2 = performanceRule($context, ['description' => 'V2', 'startsOn' => '2026-07-01']);
    $v2->update(['replaces_expectation_id' => $v1->id]);
    performanceOccurrence($v2, '2026-07-01', 11000, 12000);
    $v3 = performanceRule($context, ['description' => 'V3', 'startsOn' => '2026-08-01']);
    $v3->update(['replaces_expectation_id' => $v2->id]);
    performanceOccurrence($v3, '2026-08-01', 12000, 13000);

    expect(buildPerformance($context, $v3)['periods'])->toHaveCount(3)
        ->and(array_column(buildPerformance($context, $v3)['periods'], 'period_date'))->toBe(['2026-08-01', '2026-07-01', '2026-06-01']);
});

it('selects the latest twelve strictly by financial period', function () {
    $context = performanceContext();
    $rule = performanceRule($context);
    foreach (range(1, 14) as $month) {
        $date = Carbon\CarbonImmutable::create(2025, 1, 1)->addMonths($month - 1)->toDateString();
        performanceOccurrence($rule, $date, 10000, 10000);
    }
    $performance = buildPerformance($context, $rule);

    expect($performance['total_confirmed_count'])->toBe(14)
        ->and($performance['sample_confirmed_count'])->toBe(12)
        ->and($performance['periods'][0]['period_date'])->toBe('2026-02-01')
        ->and($performance['periods'][11]['period_date'])->toBe('2025-03-01');
});

it('builds title URLs symmetrically and isolates wallets', function () {
    $context = performanceContext();
    $payable = performanceRule($context);
    $title = AccountPayable::query()->create(['wallet_id' => $context['wallet']->id, 'expense_account_id' => $context['expense']->id,
        'payee_name' => 'Fornecedor', 'description' => 'Título', 'due_date' => '2026-08-10', 'amount_cents' => 10000, 'status' => 'pending']);
    performanceOccurrence($payable, '2026-08-01', 10000, 11000, ['account_payable_id' => $title->id]);
    $receivable = performanceRule($context, ['type' => 'receivable', 'description' => 'Receita Performance']);
    $receivableTitle = AccountReceivable::query()->create(['wallet_id' => $context['wallet']->id, 'revenue_account_id' => $context['revenue']->id,
        'customer_name' => 'Cliente', 'description' => 'Título AR', 'due_date' => '2026-08-10', 'amount_cents' => 12000, 'status' => 'pending']);
    performanceOccurrence($receivable, '2026-08-01', 12000, 13000, ['account_receivable_id' => $receivableTitle->id]);
    $foreign = performanceContext();
    $foreignRule = performanceRule($foreign);
    performanceOccurrence($foreignRule, '2026-09-01', 99999, 99999);

    expect(buildPerformance($context, $payable)['periods'])->toHaveCount(1)
        ->and(buildPerformance($context, $payable)['periods'][0]['title_url'])->toBe(route('accounts-payable.show', $title))
        ->and(buildPerformance($context, $receivable)['periods'][0]['title_url'])->toBe(route('accounts-receivable.show', $receivableTitle));
});

it('delivers performance through payable and receivable inertia indexes', function () {
    $context = performanceContext();
    $payable = performanceRule($context);
    $receivable = performanceRule($context, ['type' => 'receivable']);
    performanceOccurrence($payable, '2026-08-01', 10000, 11000);
    performanceOccurrence($receivable, '2026-08-01', 12000, 13000);
    $session = ['active_wallet' => $context['wallet']->id];

    $this->actingAs($context['user'])->withSession($session)
        ->get(route('accounts-payable.index', ['start_date' => '2026-08-01', 'end_date' => '2026-08-31']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('recurringRules.0.performance.total_confirmed_count', 1));
    $this->get(route('accounts-receivable.index', ['start_date' => '2026-08-01', 'end_date' => '2026-08-31']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('recurringRules.0.performance.total_confirmed_count', 1));
});

it('integrates terminal chain performance into overview and remains read only', function () {
    $context = performanceContext();
    $v1 = performanceRule($context, ['description' => 'Contrato V1']);
    performanceOccurrence($v1, '2026-07-01', 10000, 11000);
    $v2 = performanceRule($context, ['description' => 'Contrato V2', 'startsOn' => '2026-08-01']);
    $v2->update(['replaces_expectation_id' => $v1->id]);
    performanceOccurrence($v2, '2026-08-01', 11000, 12000);
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];

    $overview = app(BuildRecurringFinancialRulesOverview::class)->execute($context['wallet'], 'payable', now());

    expect($overview)->toHaveCount(1)->and($overview[0]['id'])->toBe($v2->id)
        ->and($overview[0]['performance']['total_confirmed_count'])->toBe(2)
        ->and([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});
