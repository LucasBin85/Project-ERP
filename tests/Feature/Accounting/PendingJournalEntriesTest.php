<?php

use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AssessJournalEntryPostingReadiness;
use App\Services\Accounting\PostJournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function pendingJournalEntriesContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.991',
        name: 'Banco da fila contábil',
    );
    $expense = AccountingTestHelper::account(
        $wallet,
        '5.99.1',
        'Despesa da fila contábil',
        'despesa',
        'debit',
    );
    $revenue = AccountingTestHelper::account(
        $wallet,
        '4.99.1',
        'Receita da fila contábil',
        'receita',
        'credit',
    );

    return compact('user', 'wallet', 'bankAccount', 'expense', 'revenue');
}

it('lists only balanced classified drafts with an identified source', function () {
    $context = pendingJournalEntriesContext();
    $ready = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-10',
        [
            [$context['bankAccount']->chartOfAccount, 'debit', 12_500],
            [$context['revenue'], 'credit', 12_500],
        ],
        'ofx',
    );
    $ready->update(['description' => 'Receita classificada pelo extrato']);

    AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-11',
        [
            [$context['bankAccount']->chartOfAccount, 'debit', 8_000],
            [$context['wallet']->suspenseAccount, 'credit', 8_000],
        ],
        'ofx',
    );

    AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-12',
        [
            [$context['expense'], 'debit', 9_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 8_500],
        ],
    );

    AccountingTestHelper::createPostedEntry(
        $context['wallet'],
        '2026-07-13',
        [
            [$context['expense'], 'debit', 7_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 7_000],
        ],
    );

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('accounting.pending-entries.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/PendingEntries/Index')
            ->where('wallet.id', $context['wallet']->id)
            ->where('summary.ready_count', 1)
            ->where('summary.ready_amount_cents', 12_500)
            ->has('entries', 1)
            ->where('entries.0.id', $ready->id)
            ->where('entries.0.source_label', 'OFX')
            ->where('entries.0.bank_accounts.0.id', $context['bankAccount']->id)
            ->where('entries.0.amount_cents', 12_500)
            ->where('entries.0.status', 'ready_for_accounting')
            ->where(
                'entries.0.journal_entry_url',
                route('journal-entries.show', $ready),
            ));
});

it('exposes a reusable readiness assessment based on current journal lines', function () {
    $context = pendingJournalEntriesContext();
    $ready = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-14',
        [
            [$context['expense'], 'debit', 3_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 3_000],
        ],
    );
    $unclassified = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-15',
        [
            [$context['wallet']->suspenseAccount, 'debit', 4_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 4_000],
        ],
        'ofx',
    );

    $service = app(AssessJournalEntryPostingReadiness::class);
    $readyResult = $service->handle($context['wallet'], $ready);
    $pendingResult = $service->handle($context['wallet'], $unclassified);

    expect($readyResult->ready)->toBeTrue()
        ->and($readyResult->reason)->toBeNull()
        ->and($pendingResult->ready)->toBeFalse()
        ->and($pendingResult->reason)->toContain('A classificar');
});

it('posts selected ready entries and leaves unselected entries in draft', function () {
    $context = pendingJournalEntriesContext();
    $selected = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-16',
        [
            [$context['expense'], 'debit', 5_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 5_000],
        ],
    );
    $unselected = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-17',
        [
            [$context['expense'], 'debit', 6_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 6_000],
        ],
    );

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('accounting.pending-entries.post-selected'), [
            'entry_ids' => [$selected->id],
        ])
        ->assertOk()
        ->assertJsonPath('posted', 1)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('errors', 0)
        ->assertJsonPath('message', '1 postados, 0 ignorados e 0 falhas.');

    expect($selected->fresh()->status)->toBe('posted')
        ->and($selected->fresh()->posted_at)->not->toBeNull()
        ->and($unselected->fresh()->status)->toBe('draft');
});

it('posts all ready entries without posting drafts that still have pending classification', function () {
    $context = pendingJournalEntriesContext();
    $first = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-18',
        [
            [$context['expense'], 'debit', 7_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 7_000],
        ],
    );
    $second = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-19',
        [
            [$context['bankAccount']->chartOfAccount, 'debit', 8_000],
            [$context['revenue'], 'credit', 8_000],
        ],
        'ofx',
    );
    $unclassified = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-20',
        [
            [$context['wallet']->suspenseAccount, 'debit', 9_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 9_000],
        ],
        'ofx',
    );

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('accounting.pending-entries.post-all'))
        ->assertOk()
        ->assertJsonPath('posted', 2)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('errors', 0);

    expect($first->fresh()->status)->toBe('posted')
        ->and($second->fresh()->status)->toBe('posted')
        ->and($unclassified->fresh()->status)->toBe('draft');
});

it('isolates posting failures and returns clear batch counts', function () {
    $context = pendingJournalEntriesContext();
    $failing = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-21',
        [
            [$context['expense'], 'debit', 10_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 10_000],
        ],
    );
    $successful = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-22',
        [
            [$context['expense'], 'debit', 11_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 11_000],
        ],
    );
    $realPostService = app(PostJournalEntry::class);

    $this->mock(PostJournalEntry::class, function (MockInterface $mock) use ($failing, $realPostService) {
        $mock->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function (JournalEntry $entry) use ($failing, $realPostService) {
                if ((int) $entry->id === (int) $failing->id) {
                    throw new RuntimeException('Falha contábil simulada.');
                }

                return $realPostService->handle($entry);
            });
    });

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('accounting.pending-entries.post-selected'), [
            'entry_ids' => [$failing->id, $successful->id],
        ])
        ->assertOk()
        ->assertJsonPath('posted', 1)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('errors', 1)
        ->assertJsonPath('error_items.0.journal_entry_id', $failing->id)
        ->assertJsonPath('error_items.0.message', 'Falha contábil simulada.');

    expect($failing->fresh()->status)->toBe('draft')
        ->and($successful->fresh()->status)->toBe('posted');
});

it('requires authentication and never posts entries from another active wallet', function () {
    $context = pendingJournalEntriesContext();
    $entry = AccountingTestHelper::createDraftEntry(
        $context['wallet'],
        '2026-07-23',
        [
            [$context['expense'], 'debit', 12_000],
            [$context['bankAccount']->chartOfAccount, 'credit', 12_000],
        ],
    );

    $this
        ->postJson(route('accounting.pending-entries.post-selected'), [
            'entry_ids' => [$entry->id],
        ])
        ->assertUnauthorized();

    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->firstOrFail();

    $this
        ->actingAs($otherUser)
        ->withSession(['active_wallet' => $otherWallet->id])
        ->postJson(route('accounting.pending-entries.post-selected'), [
            'entry_ids' => [$entry->id],
        ])
        ->assertOk()
        ->assertJsonPath('posted', 0)
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('errors', 0);

    expect($entry->fresh()->status)->toBe('draft');
});
