<?php

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\RecurringFinancialExpectation;
use App\Models\RecurringFinancialOccurrence;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\BuildMonthlyWalletClosingSummary;
use App\Services\Financial\ManageMonthlyWalletClosing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function recurringPayableContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $expense = $wallet->chartOfAccounts()->where('type', 'despesa')->where('allows_posting', true)
        ->whereDoesntHave('children')->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('type', 'passivo')->where('financial_group', 'accounts_payable')
        ->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $supplier = Supplier::query()->create([
        'wallet_id' => $wallet->id,
        'name' => 'Companhia de Energia',
        'payable_account_id' => $control->id,
        'default_expense_account_id' => $expense->id,
        'active' => true,
    ]);

    return compact('user', 'wallet', 'expense', 'control', 'supplier');
}

function recurringReceivableContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $revenue = $wallet->chartOfAccounts()->where('type', 'receita')->where('allows_posting', true)
        ->whereDoesntHave('children')->firstOrFail();
    $control = $wallet->chartOfAccounts()->where('type', 'ativo')->where('financial_group', 'accounts_receivable')
        ->where('allows_posting', true)->whereDoesntHave('children')->firstOrFail();
    $customer = Customer::query()->create([
        'wallet_id' => $wallet->id,
        'name' => 'Cliente Mensal',
        'receivable_account_id' => $control->id,
        'default_revenue_account_id' => $revenue->id,
        'active' => true,
    ]);

    return compact('user', 'wallet', 'revenue', 'control', 'customer');
}

it('stores an expected recurring payable without creating a title or accounting entry', function () {
    $context = recurringPayableContext();

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('recurring-expectations.store'), [
            'type' => 'payable',
            'supplier_id' => $context['supplier']->id,
            'description' => 'Energia elétrica',
            'frequency' => 'monthly',
            'due_day' => 10,
            'amount_mode' => 'variable',
            'expected_amount_cents' => 35000,
            'default_account_id' => $context['expense']->id,
            'starts_on' => '2026-08-01',
        ])
        ->assertSessionHasNoErrors();

    expect(RecurringFinancialExpectation::count())->toBe(1)
        ->and(RecurringFinancialOccurrence::count())->toBe(0)
        ->and(AccountPayable::count())->toBe(0)
        ->and(AccountReceivable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

it('confirms a payable occurrence by reusing the normal payable provisioning flow', function () {
    $context = recurringPayableContext();
    $expectation = RecurringFinancialExpectation::query()->create([
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
        'starts_on' => '2026-08-01',
        'status' => 'active',
    ]);

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('recurring-expectations.confirm', $expectation), [
            'period' => '2026-08',
            'amount_cents' => 37245,
            'due_date' => '2026-08-12',
        ])
        ->assertSessionHasNoErrors();

    $title = AccountPayable::firstOrFail();
    $occurrence = RecurringFinancialOccurrence::firstOrFail();

    expect($title->supplier_id)->toBe($context['supplier']->id)
        ->and($title->expense_account_id)->toBe($context['expense']->id)
        ->and($title->amount_cents)->toBe(37245)
        ->and($title->due_date->toDateString())->toBe('2026-08-12')
        ->and($title->status)->toBe('pending')
        ->and($title->provision_journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::count())->toBe(1)
        ->and(JournalEntry::firstOrFail()->status)->toBe('draft')
        ->and($occurrence->status)->toBe('confirmed')
        ->and($occurrence->account_payable_id)->toBe($title->id)
        ->and($occurrence->actual_amount_cents)->toBe(37245);

    $this->post(route('recurring-expectations.confirm', $expectation), [
        'period' => '2026-08',
        'amount_cents' => 37245,
    ])->assertSessionHasErrors('period');

    expect(AccountPayable::count())->toBe(1)
        ->and(JournalEntry::count())->toBe(1)
        ->and(RecurringFinancialOccurrence::count())->toBe(1);
});

it('confirms a receivable occurrence through the normal receivable provisioning flow', function () {
    $context = recurringReceivableContext();
    $expectation = RecurringFinancialExpectation::query()->create([
        'wallet_id' => $context['wallet']->id,
        'type' => 'receivable',
        'customer_id' => $context['customer']->id,
        'description' => 'Mensalidade de serviço',
        'frequency' => 'monthly',
        'interval_months' => 1,
        'due_day' => 15,
        'amount_mode' => 'fixed',
        'expected_amount_cents' => 50000,
        'default_account_id' => $context['revenue']->id,
        'starts_on' => '2026-08-01',
        'status' => 'active',
    ]);

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('recurring-expectations.confirm', $expectation), [
            'period' => '2026-08',
            'amount_cents' => 50000,
        ])
        ->assertSessionHasNoErrors();

    $title = AccountReceivable::firstOrFail();
    $occurrence = RecurringFinancialOccurrence::firstOrFail();

    expect($title->customer_id)->toBe($context['customer']->id)
        ->and($title->revenue_account_id)->toBe($context['revenue']->id)
        ->and($title->amount_cents)->toBe(50000)
        ->and($title->due_date->toDateString())->toBe('2026-08-15')
        ->and($title->provision_journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::count())->toBe(1)
        ->and($occurrence->status)->toBe('confirmed')
        ->and($occurrence->account_receivable_id)->toBe($title->id);
});

it('can skip a monthly occurrence without creating financial or accounting records', function () {
    $context = recurringPayableContext();
    $expectation = RecurringFinancialExpectation::query()->create([
        'wallet_id' => $context['wallet']->id,
        'type' => 'payable',
        'supplier_id' => $context['supplier']->id,
        'description' => 'Conta excepcional',
        'frequency' => 'monthly',
        'interval_months' => 1,
        'due_day' => 20,
        'amount_mode' => 'variable',
        'default_account_id' => $context['expense']->id,
        'starts_on' => '2026-08-01',
        'status' => 'active',
    ]);

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('recurring-expectations.skip', $expectation), [
            'period' => '2026-08',
            'notes' => 'Não houve cobrança neste mês.',
        ])
        ->assertSessionHasNoErrors();

    expect(RecurringFinancialOccurrence::count())->toBe(1)
        ->and(RecurringFinancialOccurrence::firstOrFail()->status)->toBe('skipped')
        ->and(AccountPayable::count())->toBe(0)
        ->and(AccountReceivable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

it('reports unresolved recurring expectations as a monthly closing blocker until resolved', function () {
    $context = recurringPayableContext();
    $expectation = RecurringFinancialExpectation::query()->create([
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
        'starts_on' => '2026-08-01',
        'status' => 'active',
    ]);

    $summary = app(BuildMonthlyWalletClosingSummary::class)->execute($context['wallet'], 2026, 8);
    expect($summary['recurring']['missing_count'])->toBe(1)
        ->and($summary['summary']['recurring_missing_count'])->toBe(1)
        ->and($summary['status'])->toBe('incomplete')
        ->and(ManageMonthlyWalletClosing::blockers($summary))
        ->toContain('Existem contas recorrentes esperadas ainda não confirmadas ou justificadas.');

    RecurringFinancialOccurrence::query()->create([
        'wallet_id' => $context['wallet']->id,
        'recurring_financial_expectation_id' => $expectation->id,
        'period_date' => '2026-08-01',
        'due_date' => '2026-08-10',
        'expected_amount_cents' => 35000,
        'status' => 'skipped',
        'skipped_at' => now(),
    ]);

    $resolved = app(BuildMonthlyWalletClosingSummary::class)->execute($context['wallet'], 2026, 8);
    expect($resolved['recurring']['missing_count'])->toBe(0)
        ->and(ManageMonthlyWalletClosing::blockers($resolved))
        ->not->toContain('Existem contas recorrentes esperadas ainda não confirmadas ou justificadas.');
});

it('does not allow one wallet to confirm another wallets recurring expectation', function () {
    $owner = recurringPayableContext();
    $other = recurringPayableContext();
    $expectation = RecurringFinancialExpectation::query()->create([
        'wallet_id' => $owner['wallet']->id,
        'type' => 'payable',
        'supplier_id' => $owner['supplier']->id,
        'description' => 'Energia elétrica',
        'frequency' => 'monthly',
        'interval_months' => 1,
        'due_day' => 10,
        'amount_mode' => 'variable',
        'default_account_id' => $owner['expense']->id,
        'starts_on' => '2026-08-01',
        'status' => 'active',
    ]);

    $this->actingAs($other['user'])->withSession(['active_wallet' => $other['wallet']->id])
        ->post(route('recurring-expectations.confirm', $expectation), [
            'period' => '2026-08',
            'amount_cents' => 10000,
        ])
        ->assertNotFound();

    expect(RecurringFinancialOccurrence::count())->toBe(0)
        ->and(AccountPayable::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});
