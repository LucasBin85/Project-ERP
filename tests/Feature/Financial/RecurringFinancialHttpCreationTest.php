<?php

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

function recurringHttpContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expenseA = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $revenueA = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $expenseB = AccountingTestHelper::account($wallet, '5.9.997', 'Despesa recorrente HTTP', 'despesa', 'debit');
    $revenueB = AccountingTestHelper::account($wallet, '4.9.997', 'Receita recorrente HTTP', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor HTTP', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expenseA->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente HTTP', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenueA->id, 'active' => true]);

    return compact('user', 'wallet', 'supplier', 'customer', 'expenseA', 'expenseB', 'revenueA', 'revenueB', 'payableControl', 'receivableControl');
}

function recurringHttpPayload(array $context, string $type, array $overrides = []): array
{
    return array_merge([
        $type === 'payable' ? 'supplier_id' : 'customer_id' => $type === 'payable' ? $context['supplier']->id : $context['customer']->id,
        'description' => 'Contrato recorrente', 'due_date' => '2026-08-12', 'amount_cents' => 150000,
        'mode' => 'recurring', 'competence_date' => '2026-08-18', 'notes' => 'Primeira competência',
        'recurring_frequency' => 'monthly', 'recurring_amount_mode' => 'fixed', 'recurring_due_day' => 10,
        'recurring_default_account_id' => $type === 'payable' ? $context['expenseB']->id : $context['revenueB']->id,
        'recurring_expected_amount_cents' => null, 'recurring_ends_on' => null,
    ], $overrides);
}

function postRecurring(array $context, string $type, array $overrides = [])
{
    return test()->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route($type === 'payable' ? 'accounts-payable.store' : 'accounts-receivable.store'), recurringHttpPayload($context, $type, $overrides));
}

it('keeps single payable creation free of recurring records', function () {
    $context = recurringHttpContext();
    postRecurring($context, 'payable', ['mode' => 'single'])->assertRedirect(route('accounts-payable.index'));

    expect(AccountPayable::count())->toBe(1)->and(RecurringFinancialExpectation::count())->toBe(0)->and(RecurringFinancialOccurrence::count())->toBe(0);
});

it('creates a fixed recurring payable with explicit classification and no future competence', function () {
    $context = recurringHttpContext();
    postRecurring($context, 'payable', ['recurring_expected_amount_cents' => 99999])->assertRedirect(route('accounts-payable.index'));
    $expectation = RecurringFinancialExpectation::query()->sole();
    $occurrence = RecurringFinancialOccurrence::query()->sole();
    $payable = AccountPayable::query()->sole();

    expect($expectation->type)->toBe('payable')->and($expectation->frequency)->toBe('monthly')
        ->and($expectation->interval_months)->toBe(1)->and($expectation->starts_on->toDateString())->toBe('2026-08-01')
        ->and($expectation->expected_amount_cents)->toBe(150000)->and($expectation->default_account_id)->toBe($context['expenseB']->id)
        ->and($occurrence->period_date->toDateString())->toBe('2026-08-01')->and($occurrence->due_date->toDateString())->toBe('2026-08-12')
        ->and($occurrence->expected_amount_cents)->toBe(150000)->and($occurrence->actual_amount_cents)->toBe(150000)
        ->and($payable->expense_account_id)->toBe($context['expenseB']->id)->and($payable->payable_account_id)->toBe($context['payableControl']->id)
        ->and(RecurringFinancialOccurrence::count())->toBe(1)->and(AccountPayable::count())->toBe(1)->and(JournalEntry::count())->toBe(1);
});

it('creates variable payable with optional initial forecast', function (?int $forecast) {
    $context = recurringHttpContext();
    postRecurring($context, 'payable', ['amount_cents' => 22135, 'recurring_amount_mode' => 'variable', 'recurring_expected_amount_cents' => $forecast])->assertRedirect(route('accounts-payable.index'));

    expect(RecurringFinancialExpectation::query()->sole()->expected_amount_cents)->toBe($forecast)
        ->and(RecurringFinancialOccurrence::query()->sole()->expected_amount_cents)->toBe($forecast)
        ->and(RecurringFinancialOccurrence::query()->sole()->actual_amount_cents)->toBe(22135);
})->with([20000, null]);

it('creates fixed and variable recurring receivables with explicit revenue classification', function (string $mode, ?int $forecast, int $expected) {
    $context = recurringHttpContext();
    postRecurring($context, 'receivable', ['amount_cents' => 35000, 'recurring_amount_mode' => $mode, 'recurring_expected_amount_cents' => $forecast])->assertRedirect(route('accounts-receivable.index'));
    $expectation = RecurringFinancialExpectation::query()->sole();
    $occurrence = RecurringFinancialOccurrence::query()->sole();
    $receivable = AccountReceivable::query()->sole();

    expect($expectation->expected_amount_cents)->toBe($expected)->and($expectation->default_account_id)->toBe($context['revenueB']->id)
        ->and($occurrence->expected_amount_cents)->toBe($expected)->and($occurrence->actual_amount_cents)->toBe(35000)
        ->and($receivable->revenue_account_id)->toBe($context['revenueB']->id)->and($receivable->receivable_account_id)->toBe($context['receivableControl']->id)
        ->and(RecurringFinancialOccurrence::count())->toBe(1)->and(AccountReceivable::count())->toBe(1)->and(JournalEntry::count())->toBe(1);
})->with([['fixed', 99999, 35000], ['variable', 30000, 30000]]);

it('rejects invalid recurring request fields without materialization', function (array $overrides, string $error) {
    $context = recurringHttpContext();
    postRecurring($context, 'payable', $overrides)->assertSessionHasErrors($error);
    expect(RecurringFinancialExpectation::count())->toBe(0)->and(RecurringFinancialOccurrence::count())->toBe(0)->and(AccountPayable::count())->toBe(0)->and(JournalEntry::count())->toBe(0);
})->with([
    [['recurring_frequency' => 'weekly'], 'recurring_frequency'],
    [['recurring_amount_mode' => 'estimated'], 'recurring_amount_mode'],
    [['recurring_due_day' => 0], 'recurring_due_day'],
    [['recurring_due_day' => 32], 'recurring_due_day'],
    [['recurring_ends_on' => '2026-07-31'], 'recurring_ends_on'],
]);

it('rejects a recurring account from another wallet atomically', function () {
    $context = recurringHttpContext();
    $foreign = recurringHttpContext();
    postRecurring($context, 'payable', ['recurring_default_account_id' => $foreign['expenseB']->id])->assertSessionHasErrors('default_account_id');

    expect(RecurringFinancialExpectation::count())->toBe(0)->and(RecurringFinancialOccurrence::count())->toBe(0)->and(AccountPayable::count())->toBe(0);
});

it('rejects an invalid counterparty before creating recurring records', function () {
    $context = recurringHttpContext();
    postRecurring($context, 'receivable', ['customer_id' => 999999])->assertSessionHasErrors('customer_id');

    expect(RecurringFinancialExpectation::count())->toBe(0)->and(RecurringFinancialOccurrence::count())->toBe(0)->and(AccountReceivable::count())->toBe(0);
});
