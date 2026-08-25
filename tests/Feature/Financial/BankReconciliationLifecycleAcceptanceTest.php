<?php

use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\ManageMonthlyWalletClosing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function acceptanceContext(string $suffix): array
{
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira acceptance '.$suffix]);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.'.$suffix, 'Banco acceptance '.$suffix);
    $counterpart = AccountingTestHelper::account($wallet, '3.'.$suffix, 'Contrapartida '.$suffix, 'patrimonio', 'credit');
    $openingEntry = AccountingTestHelper::createPostedEntry($wallet, '2026-06-30', [
        [$bankAccount->chartOfAccount, 'debit', 10000],
        [$counterpart, 'credit', 10000],
    ]);
    $periodEntry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-10', [
        [$bankAccount->chartOfAccount, 'debit', 5000],
        [$counterpart, 'credit', 5000],
    ]);
    $draftEntry = AccountingTestHelper::createDraftEntry($wallet, '2026-07-11', [
        [$bankAccount->chartOfAccount, 'debit', 9000],
        [$counterpart, 'credit', 9000],
    ]);
    $line = $periodEntry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);

    return compact('user', 'wallet', 'bankAccount', 'counterpart', 'openingEntry', 'periodEntry', 'draftEntry', 'line');
}

function acceptancePayload(array $context, int $statementBalance = 17000, bool $pending = true): array
{
    $items = [[
        'bank_statement_import_transaction_id' => null,
        'transaction_date' => '2026-07-10',
        'description' => 'Recebimento conciliado',
        'amount_cents' => 5000,
        'journal_line_id' => $context['line']->id,
    ]];

    if ($pending) {
        $items[] = [
            'bank_statement_import_transaction_id' => null,
            'transaction_date' => '2026-07-20',
            'description' => 'Movimento externo pendente',
            'amount_cents' => 2000,
            'journal_line_id' => null,
        ];
    }

    return [
        'bank_account_id' => $context['bankAccount']->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'statement_balance_cents' => $statementBalance,
        'notes' => 'Acceptance lifecycle',
        'statement_items' => $items,
    ];
}

it('accepts the complete HTTP lifecycle from contextual create through immutable completed snapshot', function () {
    $context = acceptanceContext('901');
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id]);
    $entrySnapshots = JournalEntry::query()->orderBy('id')->get()->map->getAttributes()->all();
    $counts = [BankReconciliation::query()->count(), BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()];

    $this->get(route('bank-reconciliations.create', [
        'bank_account_id' => $context['bankAccount']->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/BankReconciliations/Create')
        ->where('initial.bank_account_id', $context['bankAccount']->id)
        ->where('initial.period_start', '2026-07-01')
        ->where('initial.period_end', '2026-07-31')
        ->where('initial.statement_balance_cents', null));

    $payload = acceptancePayload($context);
    $draftPreview = $this->postJson(route('bank-reconciliations.preview'), $payload)
        ->assertOk()
        ->assertJsonPath('opening_balance_cents', 10000)
        ->assertJsonPath('book_balance_cents', 15000)
        ->assertJsonPath('calculated_statement_balance_cents', 17000)
        ->assertJsonPath('statement_items_difference_cents', 0)
        ->assertJsonPath('reconciled_balance_cents', 15000)
        ->assertJsonPath('difference_cents', -2000)
        ->assertJsonPath('pending_count', 1)
        ->assertJsonPath('status', 'draft');

    expect([BankReconciliation::query()->count(), BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()])->toBe($counts);

    $this->post(route('bank-reconciliations.store'), $payload)->assertRedirect();
    $reconciliation = BankReconciliation::query()->sole();
    $draftJson = $draftPreview->json();
    expect($reconciliation->only(['opening_balance_cents', 'statement_balance_cents', 'book_balance_cents', 'reconciled_balance_cents', 'difference_cents', 'status']))
        ->toBe(collect($draftJson)->only(['opening_balance_cents', 'statement_balance_cents', 'book_balance_cents', 'reconciled_balance_cents', 'difference_cents', 'status'])->all());

    $this->get(route('bank-reconciliations.show', $reconciliation))->assertOk();
    $this->get(route('bank-reconciliations.edit', $reconciliation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/BankReconciliations/Edit')
        ->where('reconciliation.bank_account_id', $context['bankAccount']->id)
        ->has('reconciliation.statement_items', 2));

    $updatePayload = acceptancePayload($context, 15000, false);
    $updatePreview = $this->postJson(route('bank-reconciliations.preview'), [
        ...$updatePayload,
        'bank_reconciliation_id' => $reconciliation->id,
    ])->assertOk()
        ->assertJsonPath('has_existing_overlap', false)
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('difference_cents', 0)
        ->assertJsonPath('pending_count', 0);

    unset($updatePayload['bank_account_id'], $updatePayload['period_start'], $updatePayload['period_end']);
    $this->put(route('bank-reconciliations.update', $reconciliation), $updatePayload)
        ->assertRedirect(route('bank-reconciliations.show', $reconciliation));

    $completed = $reconciliation->fresh(['items', 'statementItems']);
    $updateJson = $updatePreview->json();
    expect($completed->status)->toBe('completed')
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->only(['opening_balance_cents', 'statement_balance_cents', 'book_balance_cents', 'reconciled_balance_cents', 'difference_cents', 'status']))
        ->toBe(collect($updateJson)->only(['opening_balance_cents', 'statement_balance_cents', 'book_balance_cents', 'reconciled_balance_cents', 'difference_cents', 'status'])->all())
        ->and(JournalEntry::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($entrySnapshots);

    $snapshot = $completed->getAttributes();
    $children = [$completed->items->toArray(), $completed->statementItems->toArray()];
    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-25', [
        [$context['bankAccount']->chartOfAccount, 'debit', 3000],
        [$context['counterpart'], 'credit', 3000],
    ]);

    $this->get(route('bank-reconciliations.show', $completed))->assertOk();
    $this->get(route('bank-reconciliations.edit', $completed))->assertNotFound();
    $this->put(route('bank-reconciliations.update', $completed), $updatePayload)->assertSessionHasErrors('status');
    $this->delete(route('bank-reconciliations.destroy', $completed))->assertSessionHasErrors('status');

    expect($completed->fresh()->getAttributes())->toBe($snapshot)
        ->and([$completed->fresh()->items->toArray(), $completed->fresh()->statementItems->toArray()])->toBe($children);
});

it('discards a draft without deleting its import or journal data and allows legitimate ID reuse', function () {
    $context = acceptanceContext('902');
    $import = BankStatementImport::query()->create([
        'wallet_id' => $context['wallet']->id,
        'bank_account_id' => $context['bankAccount']->id,
        'source' => 'ofx',
        'original_filename' => 'acceptance.ofx',
        'file_hash' => hash('sha256', 'acceptance-902'),
        'status' => 'completed',
    ]);
    $transaction = BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $context['wallet']->id,
        'bank_account_id' => $context['bankAccount']->id,
        'journal_entry_id' => $context['periodEntry']->id,
        'external_id' => 'acceptance-902',
        'fit_id' => 'FIT-902',
        'posted_at' => '2026-07-10',
        'description' => 'Movimento importado',
        'amount_cents' => 5000,
        'direction' => 'in',
        'status' => 'imported',
    ]);
    $payload = acceptancePayload($context, 17000, false);
    $payload['statement_items'][0]['bank_statement_import_transaction_id'] = $transaction->id;
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id]);

    $this->post(route('bank-reconciliations.store'), $payload)->assertRedirect();
    $draft = BankReconciliation::query()->sole();
    expect($draft->status)->toBe('draft')
        ->and($draft->statementItems()->sole()->bank_statement_import_transaction_id)->toBe($transaction->id);

    $this->delete(route('bank-reconciliations.destroy', $draft))->assertRedirect(route('bank-reconciliations.index'));
    expect(BankReconciliation::query()->count())->toBe(0)
        ->and(BankStatementImportTransaction::query()->whereKey($transaction->id)->exists())->toBeTrue()
        ->and(JournalEntry::query()->whereKey($context['periodEntry']->id)->exists())->toBeTrue();

    $payload['statement_balance_cents'] = 15000;
    $this->post(route('bank-reconciliations.store'), $payload)->assertRedirect();
    expect(BankReconciliation::query()->sole()->status)->toBe('completed')
        ->and(BankReconciliationStatementItem::query()->sole()->bank_statement_import_transaction_id)->toBe($transaction->id);
});

it('creates a reconciliation directly as completed in a closed month without accounting side effects', function () {
    $context = acceptanceContext('903');
    $context['draftEntry']->update(['status' => 'posted', 'posted_at' => now()]);
    $closing = app(ManageMonthlyWalletClosing::class)->close($context['wallet'], $context['user'], 2026, 7, 'Acceptance fechado');
    $closingSnapshot = $closing->fresh()->getAttributes();
    $entrySnapshots = JournalEntry::query()->orderBy('id')->get()->map->getAttributes()->all();
    $payload = acceptancePayload($context, 15000, false);
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id]);

    $this->postJson(route('bank-reconciliations.preview'), $payload)->assertOk()->assertJsonPath('status', 'completed');
    $this->post(route('bank-reconciliations.store'), $payload)->assertRedirect();

    $completed = BankReconciliation::query()->sole();
    expect($completed->status)->toBe('completed')
        ->and($completed->completed_at)->not->toBeNull()
        ->and($closing->fresh()->getAttributes())->toBe($closingSnapshot)
        ->and(JournalEntry::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($entrySnapshots);
});

it('enforces wallet isolation and overlap policy through HTTP while allowing adjacent periods and other accounts', function () {
    $a = acceptanceContext('904');
    $b = acceptanceContext('905');
    $foreignPayload = acceptancePayload($b, 15000, false);
    $this->actingAs($b['user'])->withSession(['active_wallet' => $b['wallet']->id])
        ->post(route('bank-reconciliations.store'), $foreignPayload)
        ->assertRedirect();
    $foreignReconciliation = BankReconciliation::query()->where('wallet_id', $b['wallet']->id)->sole();

    $this->actingAs($a['user'])->withSession(['active_wallet' => $a['wallet']->id]);

    $this->get(route('bank-reconciliations.create', ['bank_account_id' => $b['bankAccount']->id]))->assertNotFound();
    $this->postJson(route('bank-reconciliations.preview'), $foreignPayload)->assertNotFound();
    $this->post(route('bank-reconciliations.store'), $foreignPayload)->assertNotFound();

    $payload = acceptancePayload($a, 15000, false);
    $this->post(route('bank-reconciliations.store'), $payload)->assertRedirect();
    $existing = BankReconciliation::query()->where('wallet_id', $a['wallet']->id)->sole();
    $this->get(route('bank-reconciliations.show', $foreignReconciliation))->assertNotFound();
    $this->get(route('bank-reconciliations.edit', $foreignReconciliation))->assertNotFound();
    $this->put(route('bank-reconciliations.update', $foreignReconciliation), [])->assertNotFound();
    $this->delete(route('bank-reconciliations.destroy', $foreignReconciliation))->assertNotFound();

    foreach ([['2026-07-01', '2026-07-31'], ['2026-07-20', '2026-08-10']] as [$start, $end]) {
        $overlap = [...$payload, 'period_start' => $start, 'period_end' => $end, 'statement_balance_cents' => 15000, 'statement_items' => []];
        $this->postJson(route('bank-reconciliations.preview'), $overlap)->assertOk()->assertJsonPath('has_existing_overlap', true);
        $this->post(route('bank-reconciliations.store'), $overlap)->assertSessionHasErrors('period_start');
    }

    $adjacent = [...$payload, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'statement_balance_cents' => 15000, 'statement_items' => []];
    $this->post(route('bank-reconciliations.store'), $adjacent)->assertRedirect();

    $otherAccount = FinancialTestHelper::bankAccount($a['wallet'], '1.1.2.904.2', 'Outro banco acceptance');
    $otherAccountPayload = [...$payload, 'bank_account_id' => $otherAccount->id, 'statement_balance_cents' => 0, 'statement_items' => []];
    $this->post(route('bank-reconciliations.store'), $otherAccountPayload)->assertRedirect();

    expect(BankReconciliation::query()->where('wallet_id', $a['wallet']->id)->count())->toBe(3)
        ->and($existing->exists)->toBeTrue();
});
