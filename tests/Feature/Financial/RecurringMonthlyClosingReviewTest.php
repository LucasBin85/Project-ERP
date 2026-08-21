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
use App\Services\Financial\BuildMonthlyWalletClosingSummary;
use App\Services\Financial\ConfirmRecurringFinancialExpectation;
use App\Services\Financial\CreateRecurringFinancialExpectation;
use App\Services\Financial\ManageMonthlyWalletClosing;
use App\Services\Financial\SkipRecurringFinancialExpectation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function recurringClosingContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.996', 'Banco review recorrente');
    $expense = AccountingTestHelper::account($wallet, '5.9.996', 'Despesa review recorrente', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.996', 'Receita review recorrente', 'receita', 'credit');
    $payableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $receivableControl = $wallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create(['wallet_id' => $wallet->id, 'name' => 'Fornecedor Closing', 'payable_account_id' => $payableControl->id, 'default_expense_account_id' => $expense->id, 'active' => true]);
    $customer = Customer::query()->create(['wallet_id' => $wallet->id, 'name' => 'Cliente Closing', 'receivable_account_id' => $receivableControl->id, 'default_revenue_account_id' => $revenue->id, 'active' => true]);

    return compact('user', 'wallet', 'bank', 'expense', 'revenue', 'supplier', 'customer');
}

function recurringClosingExpectation(array $context, array $overrides = []): RecurringFinancialExpectation
{
    $type = $overrides['type'] ?? 'payable';
    $data = array_merge([
        'type' => $type, 'description' => $type === 'payable' ? 'Internet Closing' : 'Mensalidade Closing',
        'frequency' => 'monthly', 'dueDay' => 10, 'amountMode' => 'fixed', 'expectedAmountCents' => 150000,
        'defaultAccountId' => $type === 'payable' ? $context['expense']->id : $context['revenue']->id,
        'startsOn' => '2026-01-01', 'supplierId' => $type === 'payable' ? $context['supplier']->id : null,
        'customerId' => $type === 'receivable' ? $context['customer']->id : null,
    ], $overrides);

    return app(CreateRecurringFinancialExpectation::class)->execute($context['wallet'], new RecurringFinancialExpectationDTO(...$data));
}

function recurringClosingSummary(array $context, int $month = 8): array
{
    return app(BuildMonthlyWalletClosingSummary::class)->execute($context['wallet'], 2026, $month);
}

it('reviews fixed payable and receivable with stable aggregates items and URLs', function () {
    $context = recurringClosingContext();
    recurringClosingExpectation($context);
    recurringClosingExpectation($context, ['type' => 'receivable', 'expectedAmountCents' => 200000]);
    $review = recurringClosingSummary($context)['recurring_review'];

    expect($review['payables'])->toMatchArray(['count' => 1, 'estimated_count' => 1, 'unestimated_count' => 0, 'estimated_amount_cents' => 150000])
        ->and($review['receivables'])->toMatchArray(['count' => 1, 'estimated_count' => 1, 'unestimated_count' => 0, 'estimated_amount_cents' => 200000])
        ->and($review['total_count'])->toBe(2)->and($review['unestimated_count'])->toBe(0)->and($review['has_pending'])->toBeTrue()
        ->and($review['payables']['items'][0])->toMatchArray(['due_date' => '2026-08-10', 'expected_amount_cents' => 150000, 'has_estimated_amount' => true])
        ->and($review['payables']['url'])->toBe(route('accounts-payable.index', ['start_date' => '2026-08-01', 'end_date' => '2026-08-31']))
        ->and($review['receivables']['url'])->toBe(route('accounts-receivable.index', ['start_date' => '2026-08-01', 'end_date' => '2026-08-31']));
});

it('uses estimator history and keeps unknown values nullable outside the known sum', function () {
    $context = recurringClosingContext();
    $history = recurringClosingExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => null]);
    foreach ([18400, 21300, 19600] as $index => $amount) {
        RecurringFinancialOccurrence::query()->create(['wallet_id' => $context['wallet']->id, 'recurring_financial_expectation_id' => $history->id,
            'period_date' => sprintf('2026-%02d-01', $index + 5), 'due_date' => sprintf('2026-%02d-10', $index + 5), 'status' => 'confirmed', 'actual_amount_cents' => $amount]);
    }
    recurringClosingExpectation($context, ['description' => 'Energia desconhecida', 'amountMode' => 'variable', 'expectedAmountCents' => null]);
    $payables = recurringClosingSummary($context)['recurring_review']['payables'];

    expect($payables)->toMatchArray(['count' => 2, 'estimated_count' => 1, 'unestimated_count' => 1, 'estimated_amount_cents' => 19767])
        ->and(collect($payables['items'])->firstWhere('description', 'Energia desconhecida')['expected_amount_cents'])->toBeNull()
        ->and(collect($payables['items'])->firstWhere('description', 'Energia desconhecida')['has_estimated_amount'])->toBeFalse();
});

it('removes confirmed and skipped competences without duplicating titles', function () {
    $context = recurringClosingContext();
    $confirmed = recurringClosingExpectation($context);
    $skipped = recurringClosingExpectation($context, ['description' => 'Ignorada']);
    expect(recurringClosingSummary($context)['recurring_review']['payables']['count'])->toBe(2);

    app(ConfirmRecurringFinancialExpectation::class)->execute($context['wallet'], $confirmed, CarbonImmutable::parse('2026-08-01'), 150000);
    app(SkipRecurringFinancialExpectation::class)->execute($context['wallet'], $skipped, CarbonImmutable::parse('2026-08-01'));
    $summary = recurringClosingSummary($context);

    expect($summary['recurring_review']['payables']['count'])->toBe(0)
        ->and($summary['payables']['open']['count'])->toBe(1)
        ->and(AccountPayable::count())->toBe(1);
});

it('limits review to the selected month and respects quarterly applicability', function () {
    $context = recurringClosingContext();
    recurringClosingExpectation($context, ['description' => 'Mensal']);
    recurringClosingExpectation($context, ['description' => 'Trimestral', 'frequency' => 'quarterly', 'startsOn' => '2026-01-01']);

    expect(recurringClosingSummary($context, 8)['recurring_review']['payables']['count'])->toBe(1)
        ->and(recurringClosingSummary($context, 10)['recurring_review']['payables']['count'])->toBe(2);
});

it('respects V1 V2 wallet status and end boundaries', function () {
    $context = recurringClosingContext();
    $v1 = recurringClosingExpectation($context, ['description' => 'V1', 'expectedAmountCents' => 10000]);
    $v1->update(['ends_on' => '2026-08-31']);
    recurringClosingExpectation($context, ['description' => 'V2', 'expectedAmountCents' => 12000, 'startsOn' => '2026-09-01'])->update(['replaces_expectation_id' => $v1->id]);
    recurringClosingExpectation($context, ['description' => 'Inativa'])->update(['status' => 'inactive']);
    recurringClosingExpectation($context, ['description' => 'Encerrada', 'endsOn' => '2026-07-31']);
    $foreign = recurringClosingContext();
    recurringClosingExpectation($foreign);

    expect(recurringClosingSummary($context, 8)['recurring_review']['payables']['items'][0]['description'])->toBe('V1')
        ->and(recurringClosingSummary($context, 9)['recurring_review']['payables']['items'][0]['description'])->toBe('V2');
});

it('marks historical unresolved items overdue without changing closing blockers', function () {
    CarbonImmutable::setTestNow('2026-10-20');
    $context = recurringClosingContext();
    recurringClosingExpectation($context);
    $summary = recurringClosingSummary($context);

    expect($summary['recurring_review']['payables']['items'][0]['is_overdue'])->toBeTrue()
        ->and($summary['closing_blockers'])->toBe([])
        ->and($summary['can_close'])->toBeTrue();
    CarbonImmutable::setTestNow();
});

it('does not block formal closing and remains read only for recurring records', function () {
    $context = recurringClosingContext();
    recurringClosingExpectation($context, ['amountMode' => 'variable', 'expectedAmountCents' => null]);
    $before = [RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()];
    $summary = recurringClosingSummary($context);
    $closing = app(ManageMonthlyWalletClosing::class)->close($context['wallet'], $context['user'], 2026, 8, 'Review informativo');

    expect($summary['can_close'])->toBeTrue()->and($summary['closing_blockers'])->toBe([])
        ->and($closing->status)->toBe('closed')
        ->and([RecurringFinancialExpectation::count(), RecurringFinancialOccurrence::count(), AccountPayable::count(), AccountReceivable::count(), JournalEntry::count()])->toBe($before);
});

it('returns a stable empty review without nullable aggregates', function () {
    $context = recurringClosingContext();
    $review = recurringClosingSummary($context)['recurring_review'];

    expect($review)->toMatchArray(['total_count' => 0, 'unestimated_count' => 0, 'has_pending' => false])
        ->and($review['payables'])->toMatchArray(['count' => 0, 'estimated_count' => 0, 'unestimated_count' => 0, 'estimated_amount_cents' => 0, 'items' => []])
        ->and($review['receivables'])->toMatchArray(['count' => 0, 'estimated_count' => 0, 'unestimated_count' => 0, 'estimated_amount_cents' => 0, 'items' => []]);
});

it('delivers recurring review through the existing monthly closing inertia page', function () {
    $context = recurringClosingContext();
    recurringClosingExpectation($context, ['type' => 'receivable', 'expectedAmountCents' => 80000]);

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('monthly-closing.show', ['year' => 2026, 'month' => 8]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('closing.recurring_review.total_count', 1)
            ->where('closing.recurring_review.receivables.estimated_amount_cents', 80000)
            ->where('closing.recurring_review.has_pending', true));
});
