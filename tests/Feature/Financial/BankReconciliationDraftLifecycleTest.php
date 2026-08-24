<?php

use App\DTOs\Financial\BankReconciliationDraftDTO;
use App\DTOs\Financial\BankReconciliationDTO;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MonthlyWalletClosing;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\CreateBankReconciliation;
use App\Services\Financial\DiscardBankReconciliationDraft;
use App\Services\Financial\ReplaceBankReconciliationItems;
use App\Services\Financial\UpdateBankReconciliationDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function draftLifecycleContext(string $suffix = '001'): array
{
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira lifecycle '.$suffix]);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.'.$suffix, 'Banco lifecycle '.$suffix);
    $counterpart = AccountingTestHelper::account($wallet, '3.'.$suffix, 'Contrapartida '.$suffix, 'patrimonio', 'credit');
    $entry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-10', [
        [$bankAccount->chartOfAccount, 'debit', 5000],
        [$counterpart, 'credit', 5000],
    ]);
    $line = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $reconciliation = app(CreateBankReconciliation::class)->execute($wallet, new BankReconciliationDTO(
        $bankAccount->id,
        '2026-07-01',
        '2026-07-31',
        7000,
        [[
            'transaction_date' => '2026-07-10',
            'description' => 'Movimento original',
            'amount_cents' => 5000,
            'journal_line_id' => $line->id,
        ]],
        'Nota original',
    ));

    return compact('user', 'wallet', 'bankAccount', 'entry', 'line', 'reconciliation');
}

function draftUpdateDto(array $context, array $overrides = []): BankReconciliationDraftDTO
{
    return BankReconciliationDraftDTO::fromArray(array_replace_recursive([
        'statement_balance_cents' => 5000,
        'notes' => 'Nota revisada',
        'statement_items' => [[
            'transaction_date' => '2026-07-10',
            'description' => 'Movimento revisado',
            'amount_cents' => 5000,
            'journal_line_id' => $context['line']->id,
            'bank_statement_import_transaction_id' => null,
        ]],
    ], $overrides));
}

it('updates mutable draft fields, reuses its own line and completes without changing identity', function () {
    $context = draftLifecycleContext('101');
    $originalIdentity = [
        $context['reconciliation']->bank_account_id,
        $context['reconciliation']->period_start->toDateString(),
        $context['reconciliation']->period_end->toDateString(),
    ];

    $updated = app(UpdateBankReconciliationDraft::class)->execute(
        $context['wallet'],
        $context['reconciliation'],
        draftUpdateDto($context),
    );

    expect([$updated->bank_account_id, $updated->period_start->toDateString(), $updated->period_end->toDateString()])->toBe($originalIdentity)
        ->and($updated->statement_balance_cents)->toBe(5000)
        ->and($updated->notes)->toBe('Nota revisada')
        ->and($updated->status)->toBe('completed')
        ->and($updated->completed_at)->not->toBeNull()
        ->and($updated->difference_cents)->toBe(0)
        ->and($updated->items)->toHaveCount(1)
        ->and($updated->items->first()->journal_line_id)->toBe($context['line']->id);
});

it('replaces manual items atomically and keeps a divergent review as draft', function () {
    $context = draftLifecycleContext('102');

    $updated = app(UpdateBankReconciliationDraft::class)->execute($context['wallet'], $context['reconciliation'], draftUpdateDto($context, [
        'statement_balance_cents' => 8000,
        'statement_items' => [[
            'transaction_date' => '2026-07-20',
            'description' => 'Novo item manual pendente',
            'amount_cents' => 3000,
            'journal_line_id' => null,
        ]],
    ]));

    expect($updated->status)->toBe('draft')
        ->and($updated->completed_at)->toBeNull()
        ->and($updated->items)->toHaveCount(0)
        ->and($updated->statementItems)->toHaveCount(1)
        ->and($updated->statementItems->first()->description)->toBe('Novo item manual pendente')
        ->and($updated->statementItems->first()->status)->toBe('pending');
});

it('rolls back the complete draft snapshot when item replacement fails', function () {
    $context = draftLifecycleContext('103');
    $original = $context['reconciliation']->fresh();
    $originalStatementItems = $original->statementItems()->get()->toArray();

    $failing = Mockery::mock(ReplaceBankReconciliationItems::class);
    $failing->shouldReceive('execute')->once()->andReturnUsing(function (BankReconciliation $reconciliation) {
        $reconciliation->statementItems()->delete();
        throw new RuntimeException('Falha simulada');
    });
    app()->instance(ReplaceBankReconciliationItems::class, $failing);

    expect(fn () => app(UpdateBankReconciliationDraft::class)->execute($context['wallet'], $original, draftUpdateDto($context)))
        ->toThrow(RuntimeException::class, 'Falha simulada');

    expect($original->fresh()->getAttributes())->toBe($original->getAttributes())
        ->and($original->fresh()->statementItems()->get()->toArray())->toBe($originalStatementItems);
});

it('rejects lines owned by another reconciliation while retaining the original draft', function () {
    $context = draftLifecycleContext('104');
    $secondEntry = AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-15', [
        [$context['bankAccount']->chartOfAccount, 'debit', 2000],
        [AccountingTestHelper::account($context['wallet'], '3.104.2', 'Outra contrapartida', 'patrimonio', 'credit'), 'credit', 2000],
    ]);
    $otherLine = $secondEntry->lines->firstWhere('chart_of_account_id', $context['bankAccount']->chart_of_account_id);
    $otherAccount = FinancialTestHelper::bankAccount($context['wallet'], '1.1.2.104.2', 'Banco proprietário externo');
    $other = BankReconciliation::query()->create([
        'wallet_id' => $context['wallet']->id,
        'bank_account_id' => $otherAccount->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'status' => 'draft',
    ]);
    BankReconciliationItem::query()->create(['bank_reconciliation_id' => $other->id, 'journal_line_id' => $otherLine->id, 'amount_cents' => 2000]);
    $before = $context['reconciliation']->fresh()->getAttributes();

    expect(fn () => app(UpdateBankReconciliationDraft::class)->execute($context['wallet'], $context['reconciliation'], draftUpdateDto($context, [
        'statement_items' => [[
            'transaction_date' => '2026-07-10',
            'description' => 'Linha externa',
            'amount_cents' => 2000,
            'journal_line_id' => $otherLine->id,
        ]],
    ])))->toThrow(ValidationException::class);

    expect($context['reconciliation']->fresh()->getAttributes())->toBe($before);
});

it('keeps completed reconciliations and their children immutable', function () {
    $context = draftLifecycleContext('105');
    $completed = app(UpdateBankReconciliationDraft::class)->execute($context['wallet'], $context['reconciliation'], draftUpdateDto($context));
    $snapshot = $completed->getAttributes();

    expect(fn () => app(UpdateBankReconciliationDraft::class)->execute($context['wallet'], $completed, draftUpdateDto($context)))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(DiscardBankReconciliationDraft::class)->execute($context['wallet'], $completed))
        ->toThrow(ValidationException::class)
        ->and(fn () => $completed->statementItems()->firstOrFail()->update(['description' => 'Mutação indevida']))
        ->toThrow(DomainException::class);

    expect($completed->fresh()->getAttributes())->toBe($snapshot);
});

it('discards only a draft and preserves accounting records', function () {
    $context = draftLifecycleContext('106');
    $entryCount = JournalEntry::query()->count();
    $lineCount = JournalLine::query()->count();

    app(DiscardBankReconciliationDraft::class)->execute($context['wallet'], $context['reconciliation']);

    expect(BankReconciliation::query()->count())->toBe(0)
        ->and(BankReconciliationItem::query()->count())->toBe(0)
        ->and(BankReconciliationStatementItem::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe($entryCount)
        ->and(JournalLine::query()->count())->toBe($lineCount);
});

it('allows update and discard in a closed month without changing the closing or journal entry', function () {
    $context = draftLifecycleContext('107');
    $closing = MonthlyWalletClosing::query()->create([
        'wallet_id' => $context['wallet']->id,
        'year' => 2026,
        'month' => 7,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'status' => 'closed',
        'closed_at' => now(),
        'closed_by' => $context['user']->id,
    ]);
    $entrySnapshot = $context['entry']->fresh()->getAttributes();

    $updated = app(UpdateBankReconciliationDraft::class)->execute($context['wallet'], $context['reconciliation'], draftUpdateDto($context, ['statement_balance_cents' => 8000]));
    app(DiscardBankReconciliationDraft::class)->execute($context['wallet'], $updated);

    expect($closing->fresh()->status)->toBe('closed')
        ->and($closing->fresh()->closed_at)->not->toBeNull()
        ->and($context['entry']->fresh()->getAttributes())->toBe($entrySnapshot)
        ->and(BankReconciliation::query()->count())->toBe(0);
});

it('renders a draft edit read only and excludes itself from overlap preview', function () {
    $context = draftLifecycleContext('108');
    $counts = [BankReconciliation::query()->count(), BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()];

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('bank-reconciliations.edit', $context['reconciliation']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Financial/BankReconciliations/Edit')
            ->where('reconciliation.bank_account_id', $context['bankAccount']->id)
            ->where('reconciliation.period_start', '2026-07-01T00:00:00.000000Z')
            ->has('reconciliation.statement_items', 1));

    $this->postJson(route('bank-reconciliations.preview'), [
        'bank_account_id' => $context['bankAccount']->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'bank_reconciliation_id' => $context['reconciliation']->id,
        'statement_balance_cents' => 5000,
        'notes' => null,
        'statement_items' => draftUpdateDto($context)->statementItems,
    ])->assertOk()->assertJsonPath('has_existing_overlap', false);

    $other = BankReconciliation::query()->create([
        'wallet_id' => $context['wallet']->id,
        'bank_account_id' => $context['bankAccount']->id,
        'period_start' => '2026-07-20',
        'period_end' => '2026-08-10',
        'status' => 'draft',
    ]);
    $this->postJson(route('bank-reconciliations.preview'), [
        'bank_account_id' => $context['bankAccount']->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'bank_reconciliation_id' => $context['reconciliation']->id,
        'statement_balance_cents' => 5000,
        'notes' => null,
        'statement_items' => draftUpdateDto($context)->statementItems,
    ])->assertOk()
        ->assertJsonPath('has_existing_overlap', true)
        ->assertJsonPath('existing_reconciliation_id', $other->id);

    expect(fn () => app(UpdateBankReconciliationDraft::class)->execute(
        $context['wallet'],
        $context['reconciliation'],
        draftUpdateDto($context),
    ))->toThrow(ValidationException::class);

    expect([BankReconciliation::query()->count() - 1, BankReconciliationItem::query()->count(), BankReconciliationStatementItem::query()->count()])->toBe($counts);
});

it('updates and discards drafts over HTTP while prohibiting identity fields', function () {
    $context = draftLifecycleContext('109');
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id]);
    $payload = [
        'statement_balance_cents' => 5000,
        'notes' => 'HTTP revisado',
        'statement_items' => draftUpdateDto($context)->statementItems,
    ];

    $this->put(route('bank-reconciliations.update', $context['reconciliation']), [...$payload, 'bank_account_id' => 999])
        ->assertSessionHasErrors('bank_account_id');
    $this->put(route('bank-reconciliations.update', $context['reconciliation']), $payload)
        ->assertRedirect(route('bank-reconciliations.show', $context['reconciliation']));
    $this->delete(route('bank-reconciliations.destroy', $context['reconciliation']))->assertSessionHasErrors('status');

    expect($context['reconciliation']->fresh()->status)->toBe('completed')
        ->and($context['reconciliation']->fresh()->bank_account_id)->toBe($context['bankAccount']->id);
});

it('blocks completed edit and all lifecycle endpoints across wallet boundaries', function () {
    $context = draftLifecycleContext('110');
    $foreign = draftLifecycleContext('111');
    $completed = app(UpdateBankReconciliationDraft::class)->execute($context['wallet'], $context['reconciliation'], draftUpdateDto($context));

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id]);
    $this->get(route('bank-reconciliations.edit', $completed))->assertNotFound();
    $this->get(route('bank-reconciliations.edit', $foreign['reconciliation']))->assertNotFound();
    $this->put(route('bank-reconciliations.update', $foreign['reconciliation']), [])->assertNotFound();
    $this->delete(route('bank-reconciliations.destroy', $foreign['reconciliation']))->assertNotFound();

    expect($foreign['reconciliation']->fresh())->not->toBeNull();
});

it('requires authentication for draft lifecycle routes', function () {
    $context = draftLifecycleContext('112');

    $this->get(route('bank-reconciliations.edit', $context['reconciliation']))->assertRedirect(route('login'));
    $this->put(route('bank-reconciliations.update', $context['reconciliation']), [])->assertRedirect(route('login'));
    $this->delete(route('bank-reconciliations.destroy', $context['reconciliation']))->assertRedirect(route('login'));
});

it('exposes mutation actions only for drafts in the reconciliation UI', function () {
    $show = file_get_contents(resource_path('js/pages/Financial/BankReconciliations/Show.vue'));
    $edit = file_get_contents(resource_path('js/pages/Financial/BankReconciliations/Edit.vue'));

    expect($show)->toContain("reconciliation.status === 'draft'", 'Revisar rascunho', 'Descartar rascunho', 'Confirmar descarte')
        ->and($edit)->toContain('Conta e período são imutáveis', 'bank_reconciliation_id', 'Salvar e concluir', 'Salvar rascunho');
});
