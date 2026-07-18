<?php

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\DTOs\Financial\OfxClassificationDTO;
use App\Exceptions\OfxClassificationException;
use App\Models\AccountPayable;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Supplier;
use App\DTOs\Financial\AccountPayableDTO;
use App\Services\Financial\CreateAndLinkAccountPayableFromBankStatement;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Financial\BankStatementService;
use App\Services\Financial\ClassifyOfxDraftEntry;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function payableSettlementContext(): array
{
    static $sequence = 0;
    $sequence++;

    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bankAccount = FinancialTestHelper::bankAccount(
        wallet: $wallet,
        code: '1.1.2.70'.$sequence,
        name: 'Banco para liquidação '.$sequence,
    );
    $expense = AccountingTestHelper::account(
        $wallet,
        '5.70.'.$sequence,
        'Despesa para liquidação '.$sequence,
        'despesa',
        'debit',
    );
    $otherExpense = AccountingTestHelper::account(
        $wallet,
        '5.71.'.$sequence,
        'Outra despesa '.$sequence,
        'despesa',
        'debit',
    );
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'source' => 'ofx',
        'original_filename' => 'pagamentos-'.$sequence.'.ofx',
        'file_hash' => hash('sha256', 'payable-settlement-'.$sequence),
        'status' => 'completed',
    ]);

    return compact('user', 'wallet', 'bankAccount', 'expense', 'otherExpense', 'import');
}

/**
 * @param  array<string, mixed>  $context
 * @return array{entry: JournalEntry, audit: BankStatementImportTransaction, bank_line: JournalLine, counterpart_line: JournalLine}
 */
function payableSettlementMovement(
    array $context,
    int $amountCents = 25_000,
    string $date = '2026-07-10',
    string $direction = 'out',
    string $operationType = OfxOperationTypePolicy::PAYMENT,
): array {
    static $sequence = 0;
    $sequence++;

    /** @var Wallet $wallet */
    $wallet = $context['wallet'];
    /** @var BankAccount $bankAccount */
    $bankAccount = $context['bankAccount'];
    /** @var BankStatementImport $import */
    $import = $context['import'];

    $entry = app(CreateBankImportEntry::class)->handle(
        wallet: $wallet,
        bankAccountId: $bankAccount->chart_of_account_id,
        amountCents: $amountCents,
        direction: $direction,
        entryDate: $date,
        description: 'Pagamento OFX '.$sequence,
        source: 'ofx',
        externalId: 'ofx:payable:'.$wallet->id.':'.$sequence,
        autoPostIfBalanced: false,
    );
    $bankLine = $entry->lines
        ->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $counterpartLine = $entry->lines
        ->firstWhere('chart_of_account_id', $wallet->suspense_account_id);
    $audit = BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'journal_entry_id' => $entry->id,
        'journal_line_id' => $bankLine->id,
        'external_id' => 'ofx:payable:audit:'.$wallet->id.':'.$sequence,
        'transaction_hash' => hash('sha256', 'payable-audit-'.$wallet->id.'-'.$sequence),
        'fit_id' => 'PAYABLE-'.$sequence,
        'posted_at' => $date,
        'description' => $entry->description,
        'amount_cents' => $amountCents,
        'direction' => $direction,
        'operation_type' => $operationType,
        'status' => 'imported',
        'resolution' => 'created',
    ]);

    return compact('entry', 'audit', 'bankLine', 'counterpartLine') + [
        'bank_line' => $bankLine,
        'counterpart_line' => $counterpartLine,
    ];
}

/** @param array<string, mixed> $attributes */
function payableSettlementPayable(array $context, array $attributes = []): AccountPayable
{
    return AccountPayable::query()->create(array_merge([
        'wallet_id' => $context['wallet']->id,
        'payable_account_id' => $context['wallet']->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->value('id'),
        'expense_account_id' => $context['expense']->id,
        'payee_name' => 'Fornecedor teste',
        'description' => 'Título vinculado ao extrato',
        'due_date' => '2026-07-12',
        'amount_cents' => 25_000,
        'status' => 'pending',
    ], $attributes));
}

it('lists only pending payable candidates with the same wallet and amount ordered by date proximity', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);
    $closest = payableSettlementPayable($context, [
        'description' => 'Mais próximo',
        'due_date' => '2026-07-11',
    ]);
    $sameDistanceEarlier = payableSettlementPayable($context, [
        'description' => 'Mesma distância antes',
        'due_date' => '2026-07-09',
    ]);
    $farther = payableSettlementPayable($context, [
        'description' => 'Mais distante',
        'due_date' => '2026-07-20',
    ]);
    payableSettlementPayable($context, ['amount_cents' => 24_999]);
    payableSettlementPayable($context, [
        'status' => 'paid',
        'paid_at' => '2026-07-08',
    ]);

    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->firstOrFail();
    $otherExpense = AccountingTestHelper::account(
        $otherWallet,
        '5.72.1',
        'Despesa outra wallet',
        'despesa',
        'debit',
    );
    AccountPayable::query()->create([
        'wallet_id' => $otherWallet->id,
        'expense_account_id' => $otherExpense->id,
        'payee_name' => 'Outra wallet',
        'description' => 'Não deve aparecer',
        'due_date' => '2026-07-10',
        'amount_cents' => 25_000,
        'status' => 'pending',
    ]);

    $statementTransaction = app(BankStatementService::class)->build(
        $context['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $context['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
        ),
    )->transactions->first();

    expect($statementTransaction['workflow_status'])->toBe('pending_link')
        ->and($statementTransaction['can_link_account_payable'])->toBeTrue()
        ->and($statementTransaction['linked_account_payable'])->toBeNull();

    $response = $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->getJson(route('bank-accounts.statement.payable-candidates', [
            $context['bankAccount'],
            $movement['entry'],
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('journal_entry_id', $movement['entry']->id)
        ->assertJsonCount(3, 'candidates')
        ->assertJsonPath('candidates.0.id', $sameDistanceEarlier->id)
        ->assertJsonPath('candidates.1.id', $closest->id)
        ->assertJsonPath('candidates.2.id', $farther->id)
        ->assertJsonPath('candidates.0.proximity_days', 1)
        ->assertJsonMissingPath('selected_candidate_id');
});

it('creates a payable provision and links the current statement entry as its payment', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);
    $supplier = Supplier::query()->create([
        'wallet_id' => $context['wallet']->id, 'name' => 'Fornecedor do extrato', 'active' => true,
        'payable_account_id' => $context['wallet']->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->value('id'),
        'default_expense_account_id' => $context['expense']->id,
    ]);
    $payable = app(CreateAndLinkAccountPayableFromBankStatement::class)->execute(
        $context['wallet'], $context['bankAccount'], $movement['entry'],
        new AccountPayableDTO(0, '', 'Compra criada pelo extrato', '2026-07-10', 25_000, 'Criada no extrato', supplierId: $supplier->id),
    );
    expect($payable->status)->toBe('paid')->and($payable->payment_journal_entry_id)->toBe($movement['entry']->id)
        ->and($payable->provision_journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and($movement['entry']->fresh('lines')->lines->contains('chart_of_account_id', $context['wallet']->suspense_account_id))->toBeFalse();
});

it('ignores a divergent date when creating a payable from the statement endpoint', function () {
    $context = payableSettlementContext(); $movement = payableSettlementMovement($context, date: '2026-07-10');
    $supplier = Supplier::query()->create(['wallet_id' => $context['wallet']->id, 'name' => 'Fornecedor data fixa', 'active' => true,
        'payable_account_id' => $context['wallet']->chartOfAccounts()->where('financial_group', 'accounts_payable')->where('allows_posting', true)->value('id'), 'default_expense_account_id' => $context['expense']->id]);
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])->post(route('bank-accounts.statement.create-link-payable', [$context['bankAccount'], $movement['entry']]), [
        'supplier_id' => $supplier->id, 'description' => 'Data fixa', 'due_date' => '2030-01-01',
    ])->assertSessionHasNoErrors();
    $payable = AccountPayable::query()->sole();
    expect($payable->due_date->toDateString())->toBe('2026-07-10')->and($payable->paid_at->toDateString())->toBe('2026-07-10')
        ->and($payable->provisionJournalEntry->entry_date->toDateString())->toBe('2026-07-10');
});

it('links an explicitly selected payable by reusing the OFX draft and preserving its bank line', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);
    $payable = payableSettlementPayable($context);
    $journalEntryCount = JournalEntry::query()->count();
    $journalLineCount = JournalLine::query()->count();
    $originalBankLine = $movement['bank_line']->only([
        'id',
        'journal_entry_id',
        'chart_of_account_id',
        'type',
        'amount_cents',
        'memo',
    ]);

    $response = $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $movement['entry'],
        ]), [
            'account_payable_id' => $payable->id,
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('account_payable.id', $payable->id)
        ->assertJsonPath('account_payable.status', 'paid')
        ->assertJsonPath('account_payable.paid_at', '2026-07-10')
        ->assertJsonPath('account_payable.payment_journal_entry_id', $movement['entry']->id)
        ->assertJsonPath('journal_entry.status', 'draft')
        ->assertJsonPath('journal_entry.is_balanced', true)
        ->assertJsonPath('journal_entry.ready_for_accounting', true);

    expect(JournalEntry::query()->count())->toBe($journalEntryCount)
        ->and(JournalLine::query()->count())->toBe($journalLineCount)
        ->and($movement['entry']->fresh()->status)->toBe('draft')
        ->and($movement['entry']->fresh()->is_balanced)->toBeTrue()
        ->and($movement['entry']->fresh()->settledAccountPayable?->id)->toBe($payable->id)
        ->and($movement['audit']->fresh()->settledAccountPayable?->id)->toBe($payable->id);

    expect($movement['bank_line']->fresh()->only(array_keys($originalBankLine)))
        ->toBe($originalBankLine);

    $this->assertDatabaseHas('journal_lines', [
        'id' => $movement['counterpart_line']->id,
        'journal_entry_id' => $movement['entry']->id,
        'chart_of_account_id' => $payable->payable_account_id,
        'type' => 'debit',
        'amount_cents' => 25_000,
    ]);
    $this->assertDatabaseHas('accounts_payable', [
        'id' => $payable->id,
        'status' => 'paid',
        'paid_at' => '2026-07-10 00:00:00',
        'bank_account_id' => $context['bankAccount']->id,
        'payment_journal_entry_id' => $movement['entry']->id,
    ]);
    $this->assertDatabaseHas('bank_statement_import_transactions', [
        'id' => $movement['audit']->id,
        'journal_entry_id' => $movement['entry']->id,
        'journal_line_id' => $movement['bank_line']->id,
        'classification_account_id' => $payable->payable_account_id,
        'operation_type' => OfxOperationTypePolicy::PAYMENT,
    ]);

    $statementTransaction = app(BankStatementService::class)->build(
        $context['wallet'],
        new BankStatementFiltersDTO(
            bankAccountId: $context['bankAccount']->id,
            startDate: '2026-07-01',
            endDate: '2026-07-31',
            search: '',
        ),
    )->transactions->first();

    expect($statementTransaction['workflow_status'])->toBe('ready_for_accounting')
        ->and($statementTransaction['linked_account_payable']['id'])->toBe($payable->id)
        ->and($statementTransaction['classification_account_id'])->toBe($payable->payable_account_id)
        ->and($statementTransaction['classification_label'])->toBe($payable->payableAccount->name)
        ->and($statementTransaction['can_link_account_payable'])->toBeFalse()
        ->and($statementTransaction['can_edit_operation_type'])->toBeFalse()
        ->and($statementTransaction['can_classify'])->toBeFalse();

    expect(fn () => app(ClassifyOfxDraftEntry::class)->execute(
        wallet: $context['wallet'],
        bankAccount: $context['bankAccount'],
        entry: $movement['entry']->fresh(),
        dto: new OfxClassificationDTO(
            operationType: OfxOperationTypePolicy::EXPENSE,
            destinationAccountId: $context['otherExpense']->id,
        ),
    ))->toThrow(OfxClassificationException::class, 'vinculado a uma conta a pagar');

    $this->assertDatabaseHas('journal_lines', [
        'id' => $movement['counterpart_line']->id,
        'chart_of_account_id' => $payable->payable_account_id,
    ]);
});

it('requires an explicit payable selection', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);
    payableSettlementPayable($context);

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $movement['entry'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_payable_id');

    expect($movement['entry']->fresh()->settledAccountPayable)->toBeNull();
});

it('blocks a payable with a different amount and rolls back the OFX classification', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);
    $payable = payableSettlementPayable($context, ['amount_cents' => 25_001]);

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $movement['entry'],
        ]), ['account_payable_id' => $payable->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_payable_id');

    $this->assertDatabaseHas('accounts_payable', [
        'id' => $payable->id,
        'status' => 'pending',
        'payment_journal_entry_id' => null,
    ]);
    $this->assertDatabaseHas('journal_lines', [
        'id' => $movement['counterpart_line']->id,
        'chart_of_account_id' => $context['wallet']->suspense_account_id,
    ]);
    $this->assertDatabaseHas('bank_statement_import_transactions', [
        'id' => $movement['audit']->id,
        'classification_account_id' => null,
    ]);
});

it('blocks already paid payables and duplicate movement links', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);
    $paid = payableSettlementPayable($context, [
        'status' => 'paid',
        'paid_at' => '2026-07-08',
    ]);

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $movement['entry'],
        ]), ['account_payable_id' => $paid->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_payable_id');

    $first = payableSettlementPayable($context);
    $second = payableSettlementPayable($context, ['expense_account_id' => $context['otherExpense']->id]);

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $movement['entry'],
        ]), ['account_payable_id' => $first->id])
        ->assertOk();

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $movement['entry'],
        ]), ['account_payable_id' => $second->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('journal_entry_id');

    expect($first->fresh()->status)->toBe('paid')
        ->and($second->fresh()->status)->toBe('pending')
        ->and($movement['entry']->fresh()->settledAccountPayable?->id)->toBe($first->id);
});

it('blocks incoming movements and non-payment OFX movements', function () {
    $context = payableSettlementContext();
    $incoming = payableSettlementMovement($context, direction: 'in');
    $payable = payableSettlementPayable($context);

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $incoming['entry'],
        ]), ['account_payable_id' => $payable->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('journal_entry_id');

    $expenseMovement = payableSettlementMovement(
        $context,
        operationType: OfxOperationTypePolicy::EXPENSE,
    );

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->getJson(route('bank-accounts.statement.payable-candidates', [
            $context['bankAccount'],
            $expenseMovement['entry'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('journal_entry_id');
});

it('blocks a payable from another wallet and hides movements outside the active wallet', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);
    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->firstOrFail();
    $otherExpense = AccountingTestHelper::account(
        $otherWallet,
        '5.73.1',
        'Despesa externa',
        'despesa',
        'debit',
    );
    $otherPayable = AccountPayable::query()->create([
        'wallet_id' => $otherWallet->id,
        'expense_account_id' => $otherExpense->id,
        'payee_name' => 'Fornecedor externo',
        'description' => 'Outra wallet',
        'due_date' => '2026-07-10',
        'amount_cents' => 25_000,
        'status' => 'pending',
    ]);

    $this
        ->actingAs($context['user'])
        ->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-payable', [
            $context['bankAccount'],
            $movement['entry'],
        ]), ['account_payable_id' => $otherPayable->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_payable_id');

    $this
        ->actingAs($otherUser)
        ->withSession(['active_wallet' => $otherWallet->id])
        ->getJson(route('bank-accounts.statement.payable-candidates', [
            $context['bankAccount'],
            $movement['entry'],
        ]))
        ->assertNotFound();
});

it('requires authentication for payable settlement endpoints', function () {
    $context = payableSettlementContext();
    $movement = payableSettlementMovement($context);

    $this->get(route('bank-accounts.statement.payable-candidates', [
        $context['bankAccount'],
        $movement['entry'],
    ]))->assertRedirect(route('login'));

    $this->post(route('bank-accounts.statement.link-payable', [
        $context['bankAccount'],
        $movement['entry'],
    ]), [
        'account_payable_id' => 1,
    ])->assertRedirect(route('login'));
});
