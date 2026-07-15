<?php

use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Accounting\PostJournalEntry;
use App\Services\Financial\BulkPostOfxDraftEntries;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function bulkOfxPostingContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.981',
        name: 'Banco da postagem em massa',
    );
    $otherBankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.982',
        name: 'Outro banco',
    );
    $expense = AccountingTestHelper::account(
        $wallet,
        '5.98.1',
        'Despesa para postagem em massa',
        'despesa',
        'debit',
    );
    $revenue = AccountingTestHelper::account(
        $wallet,
        '4.98.1',
        'Receita para postagem em massa',
        'receita',
        'credit',
    );
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'source' => 'ofx',
        'original_filename' => 'postagem-em-massa.ofx',
        'file_hash' => hash('sha256', 'postagem-em-massa-'.$wallet->id),
        'status' => 'completed',
    ]);

    return compact(
        'user',
        'wallet',
        'bankAccount',
        'otherBankAccount',
        'expense',
        'revenue',
        'import',
    );
}

/**
 * @param  array<string, mixed>  $context
 * @return array{entry: JournalEntry, audit: BankStatementImportTransaction, bank_line: JournalLine, counterpart_line: JournalLine}
 */
function bulkOfxDraft(
    array $context,
    string $date,
    int $amountCents,
    ?string $operationType = OfxOperationTypePolicy::EXPENSE,
    string $direction = 'out',
    bool $classified = true,
    ?BankAccount $bankAccount = null,
    ?BankStatementImport $import = null,
    string $resolution = 'created',
): array {
    static $sequence = 0;
    $sequence++;

    /** @var Wallet $wallet */
    $wallet = $context['wallet'];
    $bankAccount ??= $context['bankAccount'];
    $import ??= $context['import'];
    $destination = $direction === 'in' ? $context['revenue'] : $context['expense'];
    $entry = app(CreateBankImportEntry::class)->handle(
        wallet: $wallet,
        bankAccountId: $bankAccount->chart_of_account_id,
        amountCents: $amountCents,
        direction: $direction,
        entryDate: $date,
        description: 'OFX em massa '.$sequence,
        source: 'ofx',
        externalId: 'ofx:bulk:'.$wallet->id.':'.$sequence,
        autoPostIfBalanced: false,
    );
    $bankLine = $entry->lines
        ->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $counterpartLine = $entry->lines
        ->firstWhere('chart_of_account_id', $wallet->suspense_account_id);

    if ($classified) {
        $counterpartLine->update([
            'chart_of_account_id' => $destination->id,
            'memo' => 'Classificação para postagem em massa',
        ]);
    }

    $audit = BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'journal_entry_id' => $entry->id,
        'journal_line_id' => $bankLine->id,
        'classification_account_id' => $classified ? $destination->id : null,
        'external_id' => 'ofx:bulk:audit:'.$wallet->id.':'.$sequence,
        'transaction_hash' => hash('sha256', 'bulk-audit-'.$wallet->id.'-'.$sequence),
        'fit_id' => 'BULK-'.$sequence,
        'posted_at' => $date,
        'description' => $entry->description,
        'amount_cents' => $amountCents,
        'direction' => $direction,
        'operation_type' => $operationType,
        'status' => 'imported',
        'resolution' => $resolution,
    ]);

    return [
        'entry' => $entry,
        'audit' => $audit,
        'bank_line' => $bankLine,
        'counterpart_line' => $counterpartLine,
    ];
}

it('posts only eligible OFX drafts from the selected bank account and period', function () {
    $context = bulkOfxPostingContext();
    $first = bulkOfxDraft($context, '2026-07-10', 10_000);
    $second = bulkOfxDraft(
        $context,
        '2026-07-11',
        11_000,
        OfxOperationTypePolicy::FEE,
    );
    $outsidePeriod = bulkOfxDraft($context, '2026-06-30', 12_000);

    $otherImport = BankStatementImport::query()->create([
        'wallet_id' => $context['wallet']->id,
        'bank_account_id' => $context['otherBankAccount']->id,
        'source' => 'ofx',
        'original_filename' => 'outro-banco.ofx',
        'file_hash' => hash('sha256', 'outro-banco'),
        'status' => 'completed',
    ]);
    $otherBank = bulkOfxDraft(
        context: $context,
        date: '2026-07-12',
        amountCents: 13_000,
        bankAccount: $context['otherBankAccount'],
        import: $otherImport,
    );

    $response = $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.bulk-post', $context['bankAccount']), [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('posted', 2)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('errors', 0)
        ->assertJsonPath('message', '2 lançamentos postados. 0 ignorados por pendência. 0 falharam.');

    expect($first['entry']->fresh()->status)->toBe('posted')
        ->and($first['entry']->fresh()->posted_at)->not->toBeNull()
        ->and($second['entry']->fresh()->status)->toBe('posted')
        ->and($outsidePeriod['entry']->fresh()->status)->toBe('draft')
        ->and($otherBank['entry']->fresh()->status)->toBe('draft');
});

it('skips unclassified reserved invalid unbalanced and unresolved OFX drafts', function () {
    $context = bulkOfxPostingContext();
    $missingType = bulkOfxDraft($context, '2026-07-01', 20_001, null);
    $payment = bulkOfxDraft(
        $context,
        '2026-07-02',
        20_002,
        OfxOperationTypePolicy::PAYMENT,
    );
    $income = bulkOfxDraft(
        context: $context,
        date: '2026-07-03',
        amountCents: 20_003,
        operationType: OfxOperationTypePolicy::INCOME,
        direction: 'in',
    );
    $investment = bulkOfxDraft(
        $context,
        '2026-07-04',
        20_004,
        OfxOperationTypePolicy::INVESTMENT,
    );
    $suspense = bulkOfxDraft(
        context: $context,
        date: '2026-07-05',
        amountCents: 20_005,
        classified: false,
    );
    $missingClassification = bulkOfxDraft($context, '2026-07-06', 20_006);
    $missingClassification['audit']->update(['classification_account_id' => null]);
    $unbalanced = bulkOfxDraft($context, '2026-07-07', 20_007);
    $unbalanced['counterpart_line']->update(['amount_cents' => 20_006]);
    $pendingMatch = bulkOfxDraft($context, '2026-07-08', 20_008);

    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-08', [
        [$context['expense'], 'debit', 20_008],
        [$context['bankAccount']->chartOfAccount, 'credit', 20_008],
    ]);

    $response = $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.bulk-post', $context['bankAccount']), [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('posted', 0)
        ->assertJsonPath('skipped', 8)
        ->assertJsonPath('errors', 0)
        ->assertJsonCount(8, 'skipped_items');

    foreach ([
        $missingType,
        $payment,
        $income,
        $investment,
        $suspense,
        $missingClassification,
        $unbalanced,
        $pendingMatch,
    ] as $item) {
        expect($item['entry']->fresh()->status)->toBe('draft');
    }

    expect(collect($response->json('skipped_items'))->pluck('reason')->all())
        ->toContain(
            'Selecione o tipo da operação antes da postagem em massa.',
            'Pagamentos e receitas ficam pendentes para a futura vinculação com contas a pagar/receber.',
            'O tipo selecionado ainda não possui classificação contábil postável.',
            'O lançamento ainda possui valor em "A classificar".',
            'O lançamento não possui uma classificação contábil única e explícita.',
            'O lançamento não está balanceado.',
            'Existe um possível vínculo manual pendente de decisão.',
        );
});

it('posts a classified draft after an explicit keep decision resolves its manual match', function () {
    $context = bulkOfxPostingContext();
    $item = bulkOfxDraft(
        context: $context,
        date: '2026-07-15',
        amountCents: 35_000,
        resolution: 'kept',
    );

    AccountingTestHelper::createPostedEntry($context['wallet'], '2026-07-15', [
        [$context['expense'], 'debit', 35_000],
        [$context['bankAccount']->chartOfAccount, 'credit', 35_000],
    ]);

    $result = app(BulkPostOfxDraftEntries::class)->execute(
        wallet: $context['wallet'],
        bankAccount: $context['bankAccount'],
        startDate: '2026-07-01',
        endDate: '2026-07-31',
    );

    expect($result->posted)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->errors)->toBe(0)
        ->and($item['entry']->fresh()->status)->toBe('posted');
});

it('continues the batch and reports an isolated posting failure', function () {
    $context = bulkOfxPostingContext();
    $failing = bulkOfxDraft($context, '2026-07-20', 40_000);
    $successful = bulkOfxDraft($context, '2026-07-21', 41_000);
    $realPostService = app(PostJournalEntry::class);

    $this->mock(PostJournalEntry::class, function (MockInterface $mock) use ($failing, $realPostService) {
        $mock->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function (JournalEntry $entry) use ($failing, $realPostService) {
                if ((int) $entry->id === (int) $failing['entry']->id) {
                    throw new \RuntimeException('Falha simulada ao postar.');
                }

                return $realPostService->handle($entry);
            });
    });

    $result = app(BulkPostOfxDraftEntries::class)->execute(
        wallet: $context['wallet'],
        bankAccount: $context['bankAccount'],
        startDate: '2026-07-01',
        endDate: '2026-07-31',
    );

    expect($result->posted)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->errors)->toBe(1)
        ->and($result->errorItems)->toBe([[
            'journal_entry_id' => $failing['entry']->id,
            'message' => 'Falha simulada ao postar.',
        ]])
        ->and($failing['entry']->fresh()->status)->toBe('draft')
        ->and($successful['entry']->fresh()->status)->toBe('posted');
});

it('returns the batch result through the inertia-compatible redirect contract', function () {
    $context = bulkOfxPostingContext();
    bulkOfxDraft($context, '2026-07-25', 50_000);

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->from(route('bank-accounts.statement', [
            'bankAccount' => $context['bankAccount'],
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]))
        ->post(route('bank-accounts.statement.bulk-post', $context['bankAccount']), [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '1 lançamentos postados. 0 ignorados por pendência. 0 falharam.')
        ->assertSessionHas('ofx_bulk_post_result', fn (array $result) => $result['posted'] === 1
            && $result['skipped'] === 0
            && $result['errors'] === 0);
});

it('enforces authentication wallet ownership and period validation', function () {
    $context = bulkOfxPostingContext();

    $this->postJson(route('bank-accounts.statement.bulk-post', $context['bankAccount']), [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ])->assertUnauthorized();

    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->firstOrFail();

    $this
        ->actingAs($otherUser)
        ->withSession(['active_wallet' => $otherWallet->id])
        ->postJson(route('bank-accounts.statement.bulk-post', $context['bankAccount']), [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertNotFound();

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.bulk-post', $context['bankAccount']), [
            'start_date' => '2026-07-31',
            'end_date' => '2026-07-01',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');
});
