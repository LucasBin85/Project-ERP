<?php

use App\Models\MonthlyWalletClosing;
use App\Models\User;
use App\Services\Financial\ManageMonthlyWalletClosing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function auditableClosingContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = FinancialTestHelper::bankAccount($wallet, '1.1.2.981', 'Banco bloqueio');
    $expense = AccountingTestHelper::account($wallet, '5.9.81', 'Despesa bloqueio', 'despesa', 'debit');

    return compact('user', 'wallet', 'bank', 'expense');
}

function closeJuly(array $context, string $note = 'Conferido'): MonthlyWalletClosing
{
    return app(ManageMonthlyWalletClosing::class)->close($context['wallet'], $context['user'], 2026, 7, $note);
}

it('creates one audited closing per wallet month and saves its snapshot', function () {
    $context = auditableClosingContext();
    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-10', [
        [$context['expense'], 'debit', 1200], [$context['bank']->chartOfAccount, 'credit', 1200],
    ]);
    $closing = closeJuly($context);

    expect($closing->status)->toBe('closed')->and($closing->closed_by)->toBe($context['user']->id)
        ->and($closing->closed_at)->not->toBeNull()->and($closing->snapshot_json['accounting']['posted'])->toBe(1)
        ->and($closing->snapshot_json['summary'])->toHaveKeys(['closing_operational_cents', 'posted_accounting_cents', 'difference_cents']);

    expect(fn () => MonthlyWalletClosing::query()->create([
        'wallet_id' => $context['wallet']->id, 'year' => 2026, 'month' => 7, 'period_start' => '2026-07-01',
        'period_end' => '2026-07-31', 'status' => 'open',
    ]))->toThrow(QueryException::class);
});

it('refuses closing while classifications or drafts remain', function () {
    $context = auditableClosingContext();
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [
        [$context['expense'], 'debit', 1000], [$context['bank']->chartOfAccount, 'credit', 1000],
    ]);
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('monthly-closing.close'), ['year' => 2026, 'month' => 7])
        ->assertSessionHasErrors('closing');
    expect(MonthlyWalletClosing::query()->count())->toBe(0);

    $context = auditableClosingContext();
    AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [
        [$context['wallet']->suspenseAccount, 'debit', 1000], [$context['bank']->chartOfAccount, 'credit', 1000],
    ], 'ofx');
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('monthly-closing.close'), ['year' => 2026, 'month' => 7])
        ->assertSessionHasErrors('closing');
});

it('reopens only with a justification and keeps close history', function () {
    $context = auditableClosingContext();
    closeJuly($context, 'Fechamento original');
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('monthly-closing.reopen'), ['year' => 2026, 'month' => 7])->assertSessionHasErrors('reopen_reason');
    $this->post(route('monthly-closing.reopen'), ['year' => 2026, 'month' => 7, 'reopen_reason' => 'Correção necessária'])->assertSessionHasNoErrors();
    $closing = MonthlyWalletClosing::query()->firstOrFail();
    expect($closing->status)->toBe('reopened')->and($closing->close_note)->toBe('Fechamento original')
        ->and($closing->reopen_reason)->toBe('Correção necessária')->and($closing->reopened_by)->toBe($context['user']->id);
});

it('blocks posting classification suggestions and batches in a closed month', function () {
    $context = auditableClosingContext();
    closeJuly($context);
    $entry = AccountingTestHelper::createDraftEntry($context['wallet'], '2026-07-10', [
        [$context['expense'], 'debit', 1000], [$context['bank']->chartOfAccount, 'credit', 1000],
    ], 'ofx');
    $session = ['active_wallet' => $context['wallet']->id];
    $this->actingAs($context['user'])->withSession($session)->post(route('journal-entries.post', $entry))->assertSessionHasErrors('period');
    $this->post(route('bank-accounts.statement.classify', [$context['bank'], $entry]), [])->assertSessionHasErrors('period');
    $this->post(route('bank-accounts.statement.apply-suggestion', [$context['bank'], $entry]), [])->assertSessionHasErrors('period');
    $this->post(route('bank-accounts.statement.bulk-apply-suggestions', $context['bank']), [
        'items' => [['journal_entry_id' => $entry->id]],
    ])->assertSessionHasErrors('period');
    expect($entry->fresh()->status)->toBe('draft');
});

it('blocks dated AP AR and journal creation but permits other months after closing', function () {
    $context = auditableClosingContext();
    closeJuly($context);
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('journal-entries.store'), ['entry_date' => '2026-07-15'])->assertSessionHasErrors('period');
    $this->post(route('accounts-payable.store'), ['due_date' => '2026-07-20'])->assertSessionHasErrors('period');
    $this->post(route('accounts-receivable.store'), ['due_date' => '2026-07-20'])->assertSessionHasErrors('period');
    $this->post(route('journal-entries.store'), ['entry_date' => '2026-08-15'])->assertSessionHasErrors(['description', 'lines'])->assertSessionDoesntHaveErrors('period');
});

it('does not block views reports or another wallet', function () {
    $context = auditableClosingContext();
    closeJuly($context);
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('monthly-closing.show', ['year' => 2026, 'month' => 7]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('closing.formal_closing.status', 'closed')->where('closing.formal_closing.status_label', 'Fechado'));
    $this->get(route('general-journal.index', ['start_date' => '2026-07-01', 'end_date' => '2026-07-31']))->assertOk();

    $foreign = auditableClosingContext();
    $entry = AccountingTestHelper::createDraftEntry($foreign['wallet'], '2026-07-10', [
        [$foreign['expense'], 'debit', 1000], [$foreign['bank']->chartOfAccount, 'credit', 1000],
    ]);
    $this->actingAs($foreign['user'])->withSession(['active_wallet' => $foreign['wallet']->id])
        ->post(route('journal-entries.post', $entry))->assertSessionDoesntHaveErrors('period');
});

it('shows formal status actions and closing reasons in the page', function () {
    expect(file_get_contents(resource_path('js/pages/Financial/MonthlyClosings/Show.vue')))
        ->toContain('Status formal do mês')->toContain('Fechar mês')->toContain('Reabrir mês')
        ->toContain('Justificativa obrigatória')->toContain('O mês ainda não pode ser fechado');
});
