<?php

use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateBankReconciliation;
use App\Services\Financial\ManageMonthlyWalletClosing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function reconciliationHttpContext(string $suffix = '001'): array
{
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira HTTP '.$suffix]);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.'.$suffix, 'Banco HTTP '.$suffix);
    $counterpart = AccountingTestHelper::account($wallet, '3.'.$suffix, 'Contrapartida '.$suffix, 'patrimonio', 'credit');
    $entry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-10', [
        [$bankAccount->chartOfAccount, 'debit', 5000],
        [$counterpart, 'credit', 5000],
    ]);
    $line = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);

    return compact('user', 'wallet', 'bankAccount', 'entry', 'line');
}

function reconciliationHttpPayload(array $context, array $overrides = []): array
{
    return array_replace_recursive([
        'bank_account_id' => $context['bankAccount']->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'statement_balance_cents' => 7000,
        'notes' => 'Conferência HTTP',
        'statement_items' => [[
            'transaction_date' => '2026-07-10',
            'description' => 'Recebimento bancário',
            'amount_cents' => 5000,
            'journal_line_id' => $context['line']->id,
            'bank_statement_import_transaction_id' => null,
        ]],
    ], $overrides);
}

it('requires authentication for preview and store', function () {
    $context = reconciliationHttpContext();

    $this->post(route('bank-reconciliations.preview'), reconciliationHttpPayload($context))
        ->assertRedirect(route('login'));
    $this->post(route('bank-reconciliations.store'), reconciliationHttpPayload($context))
        ->assertRedirect(route('login'));

    expect(BankReconciliation::query()->count())->toBe(0);
});

it('returns a read-only preview with authoritative and calculated balances', function () {
    $context = reconciliationHttpContext('101');
    $entrySnapshot = $context['entry']->fresh()->getAttributes();
    $counts = [
        BankReconciliation::query()->count(),
        BankReconciliationItem::query()->count(),
        BankReconciliationStatementItem::query()->count(),
        JournalEntry::query()->count(),
    ];

    $this->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-reconciliations.preview'), reconciliationHttpPayload($context))
        ->assertOk()
        ->assertJsonPath('bank_account.id', $context['bankAccount']->id)
        ->assertJsonPath('period_start', '2026-07-01')
        ->assertJsonPath('period_end', '2026-07-31')
        ->assertJsonPath('opening_balance_cents', 0)
        ->assertJsonPath('statement_balance_cents', 7000)
        ->assertJsonPath('calculated_statement_balance_cents', 5000)
        ->assertJsonPath('statement_items_difference_cents', -2000)
        ->assertJsonPath('book_balance_cents', 5000)
        ->assertJsonPath('reconciled_balance_cents', 5000)
        ->assertJsonPath('difference_cents', -2000)
        ->assertJsonPath('statement_items.0.amount_cents', 5000)
        ->assertJsonPath('statement_items.0.status', 'reconciled')
        ->assertJsonPath('pending_count', 0)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('has_existing_overlap', false)
        ->assertJsonPath('existing_reconciliation_id', null);

    expect([
        BankReconciliation::query()->count(),
        BankReconciliationItem::query()->count(),
        BankReconciliationStatementItem::query()->count(),
        JournalEntry::query()->count(),
    ])->toBe($counts)
        ->and($context['entry']->fresh()->getAttributes())->toBe($entrySnapshot);
});

it('stores a reconciliation through the canonical service and redirects to show', function () {
    $context = reconciliationHttpContext('201');

    $response = $this->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('bank-reconciliations.store'), reconciliationHttpPayload($context));

    $reconciliation = BankReconciliation::query()->sole();
    $response->assertRedirect(route('bank-reconciliations.show', $reconciliation))
        ->assertSessionHas('success');

    expect($reconciliation->wallet_id)->toBe($context['wallet']->id)
        ->and($reconciliation->bank_account_id)->toBe($context['bankAccount']->id)
        ->and($reconciliation->statement_balance_cents)->toBe(7000)
        ->and($reconciliation->difference_cents)->toBe(-2000)
        ->and($reconciliation->statementItems)->toHaveCount(1)
        ->and($reconciliation->items)->toHaveCount(1);
});

it('protects preview and store from bank accounts outside the active wallet', function () {
    $context = reconciliationHttpContext('301');
    $foreign = reconciliationHttpContext('302');
    $payload = reconciliationHttpPayload($foreign);

    $this->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-reconciliations.preview'), $payload)
        ->assertNotFound();
    $this->post(route('bank-reconciliations.store'), $payload)
        ->assertNotFound();

    expect(BankReconciliation::query()->count())->toBe(0);
});

it('returns conventional validation errors for malformed payloads', function () {
    $context = reconciliationHttpContext('401');
    $payload = reconciliationHttpPayload($context, [
        'period_start' => '31/07/2026',
        'period_end' => '2026-07-01',
        'statement_balance_cents' => 10.5,
        'statement_items' => [],
    ]);

    $this->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-reconciliations.preview'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['period_start', 'period_end', 'statement_balance_cents']);

    $this->from('/financial/bank-reconciliations')
        ->post(route('bank-reconciliations.store'), $payload)
        ->assertRedirect('/financial/bank-reconciliations')
        ->assertSessionHasErrors(['period_start', 'period_end', 'statement_balance_cents']);
});

it('allows overlap preview but rejects overlap store with a domain validation error', function () {
    $context = reconciliationHttpContext('501');
    $existing = app(CreateBankReconciliation::class)->execute(
        $context['wallet'],
        BankReconciliationDTO::fromArray(reconciliationHttpPayload($context)),
    );

    $this->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-reconciliations.preview'), reconciliationHttpPayload($context))
        ->assertOk()
        ->assertJsonPath('has_existing_overlap', true)
        ->assertJsonPath('existing_reconciliation_id', $existing->id);

    $this->from(route('bank-reconciliations.index'))
        ->post(route('bank-reconciliations.store'), reconciliationHttpPayload($context))
        ->assertRedirect(route('bank-reconciliations.index'))
        ->assertSessionHasErrors('period_start');

    expect(BankReconciliation::query()->count())->toBe(1);
});

it('stores in a formally closed month and keeps index and show read only', function () {
    $context = reconciliationHttpContext('601');
    $closing = app(ManageMonthlyWalletClosing::class)->close(
        $context['wallet'], $context['user'], 2026, 7, 'Fechado antes da conciliação',
    );
    $entrySnapshot = $context['entry']->fresh()->getAttributes();

    $this->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->post(route('bank-reconciliations.store'), reconciliationHttpPayload($context))
        ->assertRedirect();

    $reconciliation = BankReconciliation::query()->sole();
    $counts = [BankReconciliation::query()->count(), BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()];
    $this->get(route('bank-reconciliations.index'))->assertOk();
    $this->get(route('bank-reconciliations.show', $reconciliation))->assertOk();

    expect($closing->fresh()->status)->toBe('closed')
        ->and($context['entry']->fresh()->getAttributes())->toBe($entrySnapshot)
        ->and([BankReconciliation::query()->count(), BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()])->toBe($counts);
});
