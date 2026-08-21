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
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\SkipRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;

uses(RefreshDatabase::class);

function recurringCashFlowContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = AccountingTestHelper::account($wallet, '5.9.994', 'Despesa recorrente cash flow', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.994', 'Receita recorrente cash flow', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor Recorrente', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Recorrente', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'expense', 'revenue', 'supplier', 'customer');
}

function recurringCashFlowExpectation(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';
    $data = array_merge([
        'type' => $type,
        'description' => $type === 'payable' ? 'Internet recorrente' : 'Mensalidade recorrente',
        'frequency' => 'monthly',
        'dueDay' => 10,
        'amountMode' => 'fixed',
        'expectedAmountCents' => 150000,
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2026-01-01',
        'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides);

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...$data));
}

function recurringCashFlow(array $context, string $start = '2026-09-01', string $end = '2026-09-30', string $mode = 'all', string $search = ''): array
{
    return app(BuildCashFlow::class)->handle($context['wallet'], new CashFlowFiltersDTO($start, $end, $mode, $search));
}

it('projects fixed payable and receivable expectations with stable metadata and totals', function () {
    $context = recurringCashFlowContext();
    $payable = recurringCashFlowExpectation($context);
    $receivable = recurringCashFlowExpectation($context, ['type' => 'receivable', 'expectedAmountCents' => 200000]);

    $cashFlow = recurringCashFlow($context);
    $items = collect($cashFlow['items']);
    $payableItem = $items->firstWhere('source', 'recurring_payable');
    $receivableItem = $items->firstWhere('source', 'recurring_receivable');

    expect($payableItem)->toMatchArray([
        'id' => "recurring-payable-{$payable->id}-2026-09-01",
        'date' => '2026-09-10', 'bucket' => 'projected', 'direction' => 'outflow',
        'status' => 'predicted', 'amount_cents' => -150000, 'amount_mode' => 'fixed',
        'recurring_expectation_id' => $payable->id, 'journal_entry_id' => null, 'url' => null,
    ])->and($receivableItem)->toMatchArray([
        'id' => "recurring-receivable-{$receivable->id}-2026-09-01",
        'direction' => 'inflow', 'amount_cents' => 200000, 'status' => 'predicted',
    ])->and($cashFlow['summary']['projected_inflows_cents'])->toBe(200000)
        ->and($cashFlow['summary']['projected_outflows_cents'])->toBe(150000)
        ->and($cashFlow['summary']['projected_net_cents'])->toBe(50000);
});

it('uses variable history then fallback without materializing the next competence', function () {
    $context = recurringCashFlowContext();
    $history = recurringCashFlowExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => null]);
    foreach ([18400, 21300, 19600] as $index => $amount) {
        RecurringFinancialOccurrence::query()->create([
            'wallet_id' => $context['wallet']->id, 'recurring_financial_expectation_id' => $history->id,
            'period_date' => sprintf('2026-%02d-01', $index + 6), 'due_date' => sprintf('2026-%02d-10', $index + 6),
            'status' => 'confirmed', 'actual_amount_cents' => $amount,
        ]);
    }
    recurringCashFlowExpectation($context, ['description' => 'Fallback variável', 'amountMode' => 'variable', 'expectedAmountCents' => 20000]);

    $items = collect(recurringCashFlow($context)['items'])->where('source', 'recurring_payable')->values();

    expect($items->firstWhere('recurring_expectation_id', $history->id)['amount_cents'])->toBe(-19767)
        ->and($items->firstWhere('description', 'Fallback variável')['amount_cents'])->toBe(-20000)
        ->and(RecurringFinancialOccurrence::count())->toBe(3);
});

it('keeps unknown projected values visible but out of monetary totals and marks later balances partial', function () {
    $context = recurringCashFlowContext();
    recurringCashFlowExpectation($context, ['type' => 'receivable', 'description' => 'Entrada desconhecida', 'amountMode' => 'variable', 'expectedAmountCents' => null, 'dueDay' => 8]);
    recurringCashFlowExpectation($context, ['description' => 'Saída desconhecida A', 'amountMode' => 'variable', 'expectedAmountCents' => null, 'dueDay' => 9]);
    recurringCashFlowExpectation($context, ['description' => 'Saída desconhecida B', 'amountMode' => 'variable', 'expectedAmountCents' => null, 'dueDay' => 10]);
    recurringCashFlowExpectation($context, ['description' => 'Saída conhecida', 'expectedAmountCents' => 20000, 'dueDay' => 11]);

    $cashFlow = recurringCashFlow($context);
    $items = collect($cashFlow['items']);

    expect($items->first()['amount_cents'])->toBeNull()
        ->and($items->first()['has_estimated_amount'])->toBeFalse()
        ->and($items->first()['running_projected_balance_cents'])->toBe(0)
        ->and($items->first()['running_projected_balance_complete'])->toBeFalse()
        ->and($items->last()['running_projected_balance_cents'])->toBe(-20000)
        ->and($items->last()['running_projected_balance_complete'])->toBeFalse()
        ->and($cashFlow['summary']['projected_outflows_cents'])->toBe(20000)
        ->and($cashFlow['summary']['unestimated_projected_inflows_count'])->toBe(1)
        ->and($cashFlow['summary']['unestimated_projected_outflows_count'])->toBe(2)
        ->and($cashFlow['summary']['unestimated_projected_items_count'])->toBe(3);
});

it('replaces a variable virtual estimate with the confirmed actual payable without double counting', function () {
    $context = recurringCashFlowContext();
    $expectation = recurringCashFlowExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => 12000]);
    expect(collect(recurringCashFlow($context)['items'])->where('source', 'recurring_payable'))->toHaveCount(1);

    app(ConfirmRecurringFinancialExpectation::class)->execute($context['wallet'], $expectation, CarbonImmutable::parse('2026-09-01'), 12790);
    $cashFlow = recurringCashFlow($context);

    expect(collect($cashFlow['items'])->where('source', 'recurring_payable'))->toBeEmpty()
        ->and(collect($cashFlow['items'])->where('source', 'accounts_payable'))->toHaveCount(1)
        ->and(collect($cashFlow['items'])->firstWhere('source', 'accounts_payable')['amount_cents'])->toBe(-12790)
        ->and($cashFlow['summary']['projected_outflows_cents'])->toBe(12790);
});

it('excludes skipped inactive ended and cross-wallet expectations', function () {
    $context = recurringCashFlowContext();
    $skipped = recurringCashFlowExpectation($context, ['description' => 'Skipped']);
    app(SkipRecurringFinancialExpectation::class)->execute($context['wallet'], $skipped, CarbonImmutable::parse('2026-09-01'));
    recurringCashFlowExpectation($context, ['description' => 'Inactive'])->update(['status' => 'inactive']);
    recurringCashFlowExpectation($context, ['description' => 'Ended', 'endsOn' => '2026-08-31']);
    $other = recurringCashFlowContext();
    recurringCashFlowExpectation($other);

    expect(recurringCashFlow($context)['items'])->toBeEmpty();
});

it('returns all applicable monthly and quarterly periods without query side effects', function () {
    $context = recurringCashFlowContext();
    recurringCashFlowExpectation($context, ['description' => 'Mensal']);
    recurringCashFlowExpectation($context, ['description' => 'Trimestral', 'frequency' => 'quarterly', 'startsOn' => '2026-01-01']);
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];

    $items = collect(recurringCashFlow($context, '2026-09-01', '2026-11-30')['items']);

    expect($items->where('description', 'Mensal'))->toHaveCount(3)
        ->and($items->where('description', 'Trimestral'))->toHaveCount(1)
        ->and([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});

it('applies mode and search before unknown balance completeness', function () {
    $context = recurringCashFlowContext();
    recurringCashFlowExpectation($context, ['description' => 'Energia sem estimativa', 'amountMode' => 'variable', 'expectedAmountCents' => null]);

    expect(recurringCashFlow($context, mode: 'projected')['items'])->toHaveCount(1)
        ->and(recurringCashFlow($context, mode: 'realized')['items'])->toBeEmpty()
        ->and(recurringCashFlow($context, mode: 'realized')['summary']['unestimated_projected_items_count'])->toBe(0)
        ->and(recurringCashFlow($context, search: 'Fornecedor Recorrente')['items'])->toHaveCount(1)
        ->and(recurringCashFlow($context, search: 'Energia')['items'])->toHaveCount(1)
        ->and(recurringCashFlow($context, search: 'inexistente')['summary']['unestimated_projected_items_count'])->toBe(0);
});

it('respects predecessor and successor validity without overlap in cash flow', function () {
    $context = recurringCashFlowContext();
    $v1 = recurringCashFlowExpectation($context, ['description' => 'Contrato V1', 'expectedAmountCents' => 10000]);
    $v1->update(['ends_on' => '2026-09-30']);
    recurringCashFlowExpectation($context, ['description' => 'Contrato V2', 'expectedAmountCents' => 12000, 'startsOn' => '2026-10-01'])
        ->update(['replaces_expectation_id' => $v1->id]);

    $items = collect(recurringCashFlow($context, '2026-09-01', '2026-10-31')['items']);

    expect($items->pluck('description')->all())->toBe(['Contrato V1', 'Contrato V2'])
        ->and($items->pluck('amount_cents')->all())->toBe([-10000, -12000]);
});

it('delivers recurring items and incomplete summary through the existing inertia endpoint read only', function () {
    $context = recurringCashFlowContext();
    recurringCashFlowExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => null]);
    $before = [RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('cash-flow.index', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.source', 'recurring_payable')
            ->where('items.0.amount_cents', null)
            ->where('summary.unestimated_projected_items_count', 1));

    expect([RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});
