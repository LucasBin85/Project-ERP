<?php

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\User;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Financial\BuildMonthlyWalletClosingSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function monthlyClosingContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.971', 'Banco mensal');
    $expense = AccountingTestHelper::account($wallet, '5.9.71', 'Despesa mensal', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.71', 'Receita mensal', 'receita', 'credit');

    return compact('user', 'wallet', 'bank', 'expense', 'revenue');
}

function monthlySummary(array $context, int $month = 7): array
{
    return app(BuildMonthlyWalletClosingSummary::class)->execute($context['wallet'], 2026, $month);
}

it('derives incomplete ready partially posted and closed monthly statuses', function () {
    $context = monthlyClosingContext();
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [
        [$context['bank']->chartOfAccount, 'debit', 1000], [$context['wallet']->suspenseAccount, 'credit', 1000],
    ], 'ofx');
    expect(monthlySummary($context)['status'])->toBe('incomplete');

    $context = monthlyClosingContext();
    $first = AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [
        [$context['expense'], 'debit', 1000], [$context['bank']->chartOfAccount, 'credit', 1000],
    ]);
    expect(monthlySummary($context)['status'])->toBe('ready_for_accounting');
    app(PostJournalEntry::class)->handle($first);
    $second = AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-11', [
        [$context['expense'], 'debit', 500], [$context['bank']->chartOfAccount, 'credit', 500],
    ]);
    expect(monthlySummary($context)['status'])->toBe('partially_posted');
    app(PostJournalEntry::class)->handle($second);
    expect(monthlySummary($context)['status'])->toBe('closed');
});

it('consolidates multiple active banks and keeps operational balance stable on posting', function () {
    $context = monthlyClosingContext();
    $secondBank = FinancialTestHelper::bankAccount($context['wallet'], '1.1.2.972', 'Segundo banco', ['opening_balance_cents' => 2000]);
    $entry = AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [
        [$context['bank']->chartOfAccount, 'debit', 5000], [$context['revenue'], 'credit', 5000],
    ]);
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-11', [
        [$context['expense'], 'debit', 1000], [$secondBank->chartOfAccount, 'credit', 1000],
    ]);
    $before = monthlySummary($context);
    expect($before['banks'])->toHaveCount(2)
        ->and($before['summary']['opening_operational_cents'])->toBe(0)
        ->and($before['summary']['closing_operational_cents'])->toBe(4000)
        ->and($before['summary']['posted_accounting_cents'])->toBe(0);

    app(PostJournalEntry::class)->handle($entry);
    $after = monthlySummary($context);
    expect($after['summary']['closing_operational_cents'])->toBe(4000)
        ->and($after['summary']['posted_accounting_cents'])->toBe(5000);
});

it('summarizes payable and receivable due items', function () {
    $context = monthlyClosingContext();
    AccountPayable::query()->create(['wallet_id' => $context['wallet']->id, 'expense_account_id' => $context['expense']->id,
        'payee_name' => 'Fornecedor', 'description' => 'AP julho', 'due_date' => '2026-07-05', 'amount_cents' => 2500, 'status' => 'pending']);
    AccountReceivable::query()->create(['wallet_id' => $context['wallet']->id, 'revenue_account_id' => $context['revenue']->id,
        'customer_name' => 'Cliente', 'description' => 'AR julho', 'due_date' => '2026-07-20', 'amount_cents' => 4000, 'status' => 'pending']);
    $summary = monthlySummary($context);

    expect($summary['payables']['open'])->toMatchArray(['count' => 1, 'amount_cents' => 2500])
        ->and($summary['payables']['overdue']['count'])->toBe(1)
        ->and($summary['receivables']['open'])->toMatchArray(['count' => 1, 'amount_cents' => 4000]);
});

it('isolates inactive and foreign wallet bank accounts', function () {
    $context = monthlyClosingContext();
    FinancialTestHelper::bankAccount($context['wallet'], '1.1.2.973', 'Banco inativo', ['is_active' => false]);
    $foreign = monthlyClosingContext();
    $summary = monthlySummary($context);

    expect($summary['banks'])->toHaveCount(1)
        ->and(collect($summary['banks'])->pluck('name')->all())->toBe(['Banco mensal'])
        ->and(collect($summary['banks'])->pluck('id'))->not->toContain($foreign['bank']->id);
});

it('renders an empty selected month and defaults to the current month', function () {
    $context = monthlyClosingContext();
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('monthly-closing.show', ['year' => 2025, 'month' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/MonthlyClosings/Show')
        ->where('closing.period.year', 2025)->where('closing.period.month', 2)->where('closing.status', 'incomplete')
        ->has('closing.banks', 1)->has('closing.cards', 0));

    $this->get(route('monthly-closing.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('closing.period.year', now()->year)->where('closing.period.month', now()->month));
});

it('exposes checklist and bank navigation links in the monthly page', function () {
    $context = monthlyClosingContext();
    $response = $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('monthly-closing.show', ['year' => 2026, 'month' => 7]));
    $response->assertInertia(fn (Assert $page) => $page->where('closing.status_label', 'Incompleto')
        ->where('closing.banks.0.closing_url', route('bank-accounts.closing.show', ['bankAccount' => $context['bank']->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-31']))
        ->where('closing.banks.0.statement_url', route('bank-accounts.statement', ['bankAccount' => $context['bank']->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-31'])));
    expect(file_get_contents(resource_path('js/pages/Financial/MonthlyClosings/Show.vue')))
        ->toContain('O que falta para fechar o mês')->toContain('Pendências contábeis')->toContain('Relatórios');
});
