<?php

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\MonthlyWalletClosing;
use App\Models\User;
use App\Services\Financial\BuildManagerialFinancialDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function managerialContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.991', 'Banco gerencial');
    $expense = AccountingTestHelper::account($wallet, '5.9.91', 'Despesa gerencial', 'despesa', 'debit');
    $revenue = AccountingTestHelper::account($wallet, '4.9.91', 'Receita gerencial', 'receita', 'credit');
    $investment = AccountingTestHelper::account($wallet, '1.3.91', 'Investimento gerencial', 'ativo', 'debit');
    $investment->update(['financial_group' => 'investments']);

    return compact('user', 'wallet', 'bank', 'expense', 'revenue', 'investment');
}

function managerialSummary(array $context, int $month = 7): array
{
    return app(BuildManagerialFinancialDashboard::class)->execute($context['wallet'], 2026, $month);
}

it('consolidates multiple banks and monthly operational movements', function () {
    $context = managerialContext();
    $second = FinancialTestHelper::bankAccount($context['wallet'], '1.1.2.992', 'Segundo banco');
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [
        [$context['bank']->chartOfAccount, 'debit', 5000], [$context['revenue'], 'credit', 5000],
    ]);
    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-11', [
        [$context['expense'], 'debit', 1200], [$second->chartOfAccount, 'credit', 1200],
    ]);
    $dashboard = managerialSummary($context);

    expect($dashboard['banks'])->toHaveCount(2)
        ->and($dashboard['cards']['inflows_cents'])->toBe(5000)
        ->and($dashboard['cards']['outflows_cents'])->toBe(1200)
        ->and($dashboard['cards']['net_result_cents'])->toBe(3800)
        ->and($dashboard['cards']['bank_operational_cents'])->toBe(3800)
        ->and($dashboard['cards']['accounting_pending_count'])->toBe(1);
});

it('summarizes open AP AR and posted expense revenue rankings', function () {
    $context = managerialContext();
    AccountPayable::query()->create(['wallet_id' => $context['wallet']->id, 'expense_account_id' => $context['expense']->id,
        'payee_name' => 'Fornecedor', 'description' => 'AP', 'due_date' => '2026-07-05', 'amount_cents' => 2200, 'status' => 'pending']);
    AccountReceivable::query()->create(['wallet_id' => $context['wallet']->id, 'revenue_account_id' => $context['revenue']->id,
        'customer_name' => 'Cliente', 'description' => 'AR', 'due_date' => '2026-07-25', 'amount_cents' => 3500, 'status' => 'pending']);
    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-12', [
        [$context['expense'], 'debit', 900], [$context['bank']->chartOfAccount, 'credit', 900],
    ]);
    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-13', [
        [$context['bank']->chartOfAccount, 'debit', 1700], [$context['revenue'], 'credit', 1700],
    ]);
    $dashboard = managerialSummary($context);

    expect($dashboard['cards']['payables_open_cents'])->toBe(2200)
        ->and($dashboard['cards']['receivables_open_cents'])->toBe(3500)
        ->and($dashboard['payables']['overdue']['count'])->toBe(1)
        ->and($dashboard['receivables']['expected']['count'])->toBe(1)
        ->and($dashboard['rankings']['expenses'][0]['amount_cents'])->toBe(900)
        ->and($dashboard['rankings']['revenues'][0]['amount_cents'])->toBe(1700);
});

it('uses posted investment balances and exposes formal monthly closing status', function () {
    $context = managerialContext();
    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-10', [
        [$context['investment'], 'debit', 8000], [$context['bank']->chartOfAccount, 'credit', 8000],
    ]);
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-11', [
        [$context['investment'], 'debit', 2000], [$context['bank']->chartOfAccount, 'credit', 2000],
    ]);
    MonthlyWalletClosing::query()->create(['wallet_id' => $context['wallet']->id, 'year' => 2026, 'month' => 7,
        'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'closed', 'closed_at' => now(), 'closed_by' => $context['user']->id]);
    $dashboard = managerialSummary($context);

    expect($dashboard['cards']['investments_cents'])->toBe(8000)
        ->and($dashboard['investments']['accounts_count'])->toBeGreaterThanOrEqual(1)
        ->and($dashboard['closing']['status'])->toBe('formally_closed')
        ->and($dashboard['closing']['status_label'])->toBe('Fechado formalmente');
});

it('does not mix another wallet and handles a month without data', function () {
    $context = managerialContext();
    $foreign = managerialContext();
    AccountingTestHelper::createPostedEntry($foreign['wallet'], '2026-06-10', [
        [$foreign['bank']->chartOfAccount, 'debit', 9999], [$foreign['revenue'], 'credit', 9999],
    ]);
    $dashboard = managerialSummary($context, 6);

    expect($dashboard['banks'])->toHaveCount(1)
        ->and(collect($dashboard['banks'])->pluck('id'))->not->toContain($foreign['bank']->id)
        ->and($dashboard['cards']['inflows_cents'])->toBe(0)
        ->and($dashboard['rankings']['revenues'])->toBe([]);
});

it('renders the active wallet dashboard and changes month and year', function () {
    $context = managerialContext();
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('dashboard', ['year' => 2026, 'month' => 7]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index')
            ->where('wallet.id', $context['wallet']->id)->where('dashboard.period.year', 2026)
            ->where('dashboard.period.month', 7)->has('dashboard.attention', 5)->has('dashboard.banks', 1));
    expect(file_get_contents(resource_path('js/pages/Dashboard/Index.vue')))
        ->toContain('Resumo do mês')->toContain('O que precisa de atenção')->toContain('Fechamento do mês');
});
