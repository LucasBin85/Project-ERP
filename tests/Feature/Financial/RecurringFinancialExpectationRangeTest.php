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
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\ListRecurringFinancialExpectationsForRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

function rangeContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = AccountingTestHelper::account($wallet, '5.9.991', 'Despesa recorrente range', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.991', 'Receita recorrente range', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Vivo Range', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Range', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function rangeExpectation(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';
    $data = array_merge([
        'type' => $type, 'description' => 'Internet Range', 'frequency' => 'monthly', 'dueDay' => 10,
        'amountMode' => 'fixed', 'expectedAmountCents' => 12000,
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2026-01-01', 'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides);

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...$data));
}

function listRange(array $context, string $type, string $start = '2026-09-01', string $end = '2026-12-31'): array
{
    return app(ListRecurringFinancialExpectationsForRange::class)->execute($context['wallet'], $type, CarbonImmutable::parse($start), CarbonImmutable::parse($end));
}

it('derives monthly virtual items across months without materializing records', function () {
    $context = rangeContext();
    rangeExpectation($context);
    $before = [RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    $items = listRange($context, 'payable');

    expect($items)->toHaveCount(4)
        ->and(array_column($items, 'period_date'))->toBe(['2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'])
        ->and(array_column($items, 'due_date'))->toBe(['2026-09-10', '2026-10-10', '2026-11-10', '2026-12-10'])
        ->and($items[0]['expected_amount_cents'])->toBe(12000)
        ->and([RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});

it('excludes resolved inactive foreign wallet and wrong type expectations', function () {
    $context = rangeContext();
    $confirmed = rangeExpectation($context);
    $skipped = rangeExpectation($context, ['description' => 'Skipped']);
    rangeExpectation($context, ['description' => 'Inactive'])->update(['status' => 'inactive']);
    rangeExpectation($context, ['type' => 'receivable']);
    foreach ([[$confirmed, 'confirmed'], [$skipped, 'skipped']] as [$expectation, $status]) {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $context['wallet']->id, 'recurring_financial_expectation_id' => $expectation->id, 'period_date' => '2026-09-01', 'due_date' => '2026-09-10', 'status' => $status]);
    }
    $other = rangeContext();
    rangeExpectation($other);

    expect(listRange($context, 'payable', '2026-09-01', '2026-09-30'))->toBeEmpty()
        ->and(listRange($context, 'receivable', '2026-09-01', '2026-09-30'))->toHaveCount(1);
});

it('respects due date filters and each supported periodicity', function (string $frequency, string $start, array $months) {
    $context = rangeContext();
    rangeExpectation($context, ['frequency' => $frequency]);
    expect(array_column(listRange($context, 'payable', $start, '2026-12-31'), 'period_date'))->toBe($months)
        ->and(listRange($context, 'payable', '2026-09-15', '2026-09-30'))->toBeEmpty();
})->with([
    'monthly' => ['monthly', '2026-09-01', ['2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01']],
    'quarterly' => ['quarterly', '2026-09-01', ['2026-10-01']],
    'semiannual' => ['semiannual', '2026-07-01', ['2026-07-01']],
    'annual' => ['annual', '2026-01-01', ['2026-01-01']],
]);

it('uses variable history fallback and marks overdue relative to today', function () {
    CarbonImmutable::setTestNow('2026-09-20');
    $context = rangeContext();
    $variable = rangeExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => null]);
    foreach ([10000, 11000, 12000] as $index => $amount) {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $context['wallet']->id, 'recurring_financial_expectation_id' => $variable->id, 'period_date' => sprintf('2026-%02d-01', $index + 6), 'due_date' => sprintf('2026-%02d-10', $index + 6), 'status' => 'confirmed', 'actual_amount_cents' => $amount]);
    }
    $item = listRange($context, 'payable', '2026-09-01', '2026-09-30')[0];
    expect($item['expected_amount_cents'])->toBe(11000)->and($item['is_overdue'])->toBeTrue();
});

it('sends virtual items in AP and AR indexes without writes', function () {
    $context = rangeContext();
    rangeExpectation($context);
    rangeExpectation($context, ['type' => 'receivable']);
    $before = [RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    $session = ['active_wallet' => $context['wallet']->id];
    $this->actingAs($context['user'])->withSession($session)->get(route('accounts-payable.index', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('recurringExpectedPayables', 1)->where('recurringExpectedPayables.0.type', 'payable'));
    $this->actingAs($context['user'])->withSession($session)->get(route('accounts-receivable.index', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('recurringExpectedReceivables', 1)->where('recurringExpectedReceivables.0.type', 'receivable'));
    expect([RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});

it('confirms and skips through semantic AP and AR endpoints with wallet and type protection', function () {
    $context = rangeContext();
    $payable = rangeExpectation($context);
    $receivable = rangeExpectation($context, ['type' => 'receivable']);
    $session = ['active_wallet' => $context['wallet']->id];
    $confirm = ['period_date' => '2026-09-01', 'due_date' => '2026-09-12', 'actual_amount_cents' => 12000, 'notes' => 'Confirmado inline'];
    $this->actingAs($context['user'])->withSession($session)->post(route('accounts-payable.recurring.confirm', $payable), $confirm)
        ->assertRedirect()->assertSessionHas('success');
    $this->actingAs($context['user'])->withSession($session)->post(route('accounts-receivable.recurring.skip', $receivable), ['period_date' => '2026-09-01'])
        ->assertRedirect()->assertSessionHas('success');
    expect($payable->occurrences()->sole()->status)->toBe('confirmed')
        ->and($payable->occurrences()->sole()->expected_amount_cents)->toBe(12000)
        ->and(AccountPayable::count())->toBe(1)->and(JournalEntry::count())->toBe(1)
        ->and($receivable->occurrences()->sole()->status)->toBe('skipped')->and(AccountReceivable::count())->toBe(0);
    $this->post(route('accounts-payable.recurring.confirm', $receivable), $confirm)->assertNotFound();
    $other = rangeContext();
    $foreign = rangeExpectation($other);
    $this->post(route('accounts-payable.recurring.confirm', $foreign), $confirm)->assertNotFound();
    $this->post(route('accounts-payable.recurring.confirm', $payable), $confirm)->assertSessionHasErrors('period');
});
