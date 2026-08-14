<?php

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function recurringFoundationContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)
        ->whereDoesntHave('children')->firstOrFail();
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')
        ->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)
        ->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')
        ->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create([
        'wallet_id' => $wallet->id,
        'name' => 'Companhia de Energia',
        'payable_account_id' => $payableControl->id,
        'default_expense_account_id' => $expense->id,
        'active' => true,
    ]);
    $customer = Customer::query()->create([
        'wallet_id' => $wallet->id,
        'name' => 'Cliente Mensal',
        'receivable_account_id' => $receivableControl->id,
        'default_revenue_account_id' => $revenue->id,
        'active' => true,
    ]);

    return compact('wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function recurringFoundationExpectation(array $overrides = []): RecurringFinancialExpectation
{
    $context = recurringFoundationContext();

    return RecurringFinancialExpectation::query()->create(array_merge([
        'wallet_id' => $context['wallet']->id,
        'type' => 'payable',
        'supplier_id' => $context['supplier']->id,
        'description' => 'Energia elétrica',
        'frequency' => 'monthly',
        'interval_months' => 1,
        'due_day' => 10,
        'amount_mode' => 'variable',
        'expected_amount_cents' => 35000,
        'default_account_id' => $context['expense']->id,
        'starts_on' => '2026-01-23',
        'status' => 'active',
    ], $overrides));
}

it('persists an expectation without creating occurrences titles or journal entries', function () {
    recurringFoundationExpectation();

    expect(RecurringFinancialExpectation::count())->toBe(1)
        ->and(RecurringFinancialOccurrence::count())->toBe(0)
        ->and(AccountPayable::count())->toBe(0)
        ->and(AccountReceivable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

it('exposes wallet counterparty and default account relationships', function () {
    $payable = recurringFoundationExpectation();
    $context = recurringFoundationContext();
    $receivable = recurringFoundationExpectation([
        'wallet_id' => $context['wallet']->id,
        'type' => 'receivable',
        'supplier_id' => null,
        'customer_id' => $context['customer']->id,
        'default_account_id' => $context['revenue']->id,
    ]);

    expect($payable->wallet->id)->toBe($payable->supplier->wallet_id)
        ->and($payable->defaultAccount->is($payable->supplier->defaultExpenseAccount))->toBeTrue()
        ->and($payable->counterpartyName())->toBe('Companhia de Energia')
        ->and($receivable->wallet->is($context['wallet']))->toBeTrue()
        ->and($receivable->customer->is($context['customer']))->toBeTrue()
        ->and($receivable->defaultAccount->is($context['revenue']))->toBeTrue()
        ->and($receivable->counterpartyName())->toBe('Cliente Mensal')
        ->and(recurringFoundationExpectation(['supplier_id' => null])->counterpartyName())->toBeNull();
});

it('reports whether an expectation is active', function () {
    expect(recurringFoundationExpectation()->isActive())->toBeTrue()
        ->and(recurringFoundationExpectation(['status' => 'inactive'])->isActive())->toBeFalse();
});

it('applies monthly quarterly semiannual and annual intervals by monthly competence', function () {
    $cases = [
        [1, '2026-01-01', true],
        [1, '2026-02-28', true],
        [3, '2026-04-30', true],
        [3, '2026-03-01', false],
        [6, '2026-07-01', true],
        [6, '2026-06-30', false],
        [12, '2027-01-31', true],
        [12, '2026-12-01', false],
    ];

    foreach ($cases as [$interval, $period, $expected]) {
        expect(recurringFoundationExpectation(['interval_months' => $interval])
            ->isApplicableTo(CarbonImmutable::parse($period)))->toBe($expected);
    }
});

it('respects start end and inactive bounds at monthly competence', function () {
    expect(recurringFoundationExpectation()->isApplicableTo(CarbonImmutable::parse('2026-01-01')))->toBeTrue()
        ->and(recurringFoundationExpectation()->isApplicableTo(CarbonImmutable::parse('2025-12-31')))->toBeFalse()
        ->and(recurringFoundationExpectation(['ends_on' => '2026-06-15'])
            ->isApplicableTo(CarbonImmutable::parse('2026-07-01')))->toBeFalse()
        ->and(recurringFoundationExpectation(['status' => 'inactive'])
            ->isApplicableTo(CarbonImmutable::parse('2026-01-01')))->toBeFalse();
});

it('clamps due dates to the final day available in the month', function () {
    expect(recurringFoundationExpectation(['due_day' => 10])
        ->dueDateForPeriod(CarbonImmutable::parse('2026-08-25'))->toDateString())->toBe('2026-08-10')
        ->and(recurringFoundationExpectation(['due_day' => 31])
            ->dueDateForPeriod(CarbonImmutable::parse('2026-04-01'))->toDateString())->toBe('2026-04-30')
        ->and(recurringFoundationExpectation(['due_day' => 31])
            ->dueDateForPeriod(CarbonImmutable::parse('2026-02-01'))->toDateString())->toBe('2026-02-28');
});

it('normalizes occurrence dates to the first day of their monthly competence', function () {
    $expectation = recurringFoundationExpectation();
    $occurrence = RecurringFinancialOccurrence::query()->create([
        'wallet_id' => $expectation->wallet_id,
        'recurring_financial_expectation_id' => $expectation->id,
        'period_date' => '2026-08-18',
        'due_date' => '2026-08-10',
        'status' => 'skipped',
        'skipped_at' => now(),
    ]);

    expect($occurrence->period_date->toDateString())->toBe('2026-08-01')
        ->and($occurrence->fresh()->period_date->toDateString())->toBe('2026-08-01')
        ->and($occurrence->expectation->is($expectation))->toBeTrue()
        ->and($occurrence->wallet->is($expectation->wallet))->toBeTrue()
        ->and($occurrence->accountPayable)->toBeNull()
        ->and($occurrence->accountReceivable)->toBeNull()
        ->and(AccountPayable::count())->toBe(0)
        ->and(AccountReceivable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

it('rejects different dates in the same monthly competence for one expectation', function () {
    $expectation = recurringFoundationExpectation();
    $attributes = [
        'wallet_id' => $expectation->wallet_id,
        'recurring_financial_expectation_id' => $expectation->id,
        'due_date' => '2026-08-10',
        'status' => 'skipped',
        'skipped_at' => now(),
    ];

    RecurringFinancialOccurrence::query()->create($attributes + ['period_date' => '2026-08-10']);

    expect(fn () => RecurringFinancialOccurrence::query()->create($attributes + ['period_date' => '2026-08-25']))
        ->toThrow(QueryException::class)
        ->and(RecurringFinancialOccurrence::firstOrFail()->period_date->toDateString())->toBe('2026-08-01');
});
