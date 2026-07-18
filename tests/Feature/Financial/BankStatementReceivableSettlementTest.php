<?php

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Models\AccountReceivable;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Customer;
use App\DTOs\Financial\AccountReceivableDTO;
use App\Services\Financial\CreateAndLinkAccountReceivableFromBankStatement;
use App\Services\Accounting\CreateBankImportEntry;
use App\Services\Financial\BankStatementService;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

function receivableSettlementContext(): array
{
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.801', 'Banco recebimentos');
    $revenue = AccountingTestHelper::account($wallet, '4.80.1', 'Receita de serviços', 'receita', 'credit');
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id, 'bank_account_id' => $bankAccount->id, 'source' => 'ofx',
        'original_filename' => 'recebimentos.ofx', 'file_hash' => hash('sha256', (string) $user->id), 'status' => 'completed',
    ]);

    return compact('user', 'wallet', 'bankAccount', 'revenue', 'import');
}

function receivableMovement(array $context, int $amount = 25000, string $direction = 'in', string $type = OfxOperationTypePolicy::INCOME): array
{
    static $sequence = 0;
    $sequence++;
    $entry = app(CreateBankImportEntry::class)->handle(
        wallet: $context['wallet'], bankAccountId: $context['bankAccount']->chart_of_account_id,
        amountCents: $amount, direction: $direction, entryDate: '2026-07-10', description: 'Receita OFX',
        source: 'ofx', externalId: 'ofx:receivable:'.$sequence, autoPostIfBalanced: false,
    );
    $bankLine = $entry->lines->firstWhere('chart_of_account_id', $context['bankAccount']->chart_of_account_id);
    $counterpart = $entry->lines->firstWhere('chart_of_account_id', $context['wallet']->suspense_account_id);
    $audit = BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $context['import']->id, 'wallet_id' => $context['wallet']->id,
        'bank_account_id' => $context['bankAccount']->id, 'journal_entry_id' => $entry->id, 'journal_line_id' => $bankLine->id,
        'external_id' => 'audit:'.$sequence, 'transaction_hash' => hash('sha256', 'audit'.$sequence), 'fit_id' => 'REC-'.$sequence,
        'posted_at' => '2026-07-10', 'description' => 'Receita OFX', 'amount_cents' => $amount,
        'direction' => $direction, 'operation_type' => $type, 'status' => 'imported', 'resolution' => 'created',
    ]);

    return compact('entry', 'bankLine', 'counterpart', 'audit');
}

function receivableTitle(array $context, array $attributes = []): AccountReceivable
{
    return AccountReceivable::query()->create(array_merge([
        'wallet_id' => $context['wallet']->id,
        'receivable_account_id' => $context['wallet']->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->value('id'),
        'revenue_account_id' => $context['revenue']->id,
        'customer_name' => 'Cliente teste', 'description' => 'Mensalidade', 'due_date' => '2026-07-12',
        'amount_cents' => 25000, 'status' => 'pending',
    ], $attributes));
}

it('lists pending receivables of the same amount ordered by statement date proximity without auto selection', function () {
    $context = receivableSettlementContext();
    $movement = receivableMovement($context);
    $near = receivableTitle($context, ['due_date' => '2026-07-11']);
    $far = receivableTitle($context, ['due_date' => '2026-07-20']);
    receivableTitle($context, ['amount_cents' => 1]);
    receivableTitle($context, ['status' => 'received', 'received_at' => '2026-07-01']);

    $statement = app(BankStatementService::class)->build($context['wallet'], new BankStatementFiltersDTO(
        bankAccountId: $context['bankAccount']->id, startDate: '2026-07-01', endDate: '2026-07-31',
    ))->transactions->first();
    expect($statement['workflow_status'])->toBe('pending_link')->and($statement['can_link_account_receivable'])->toBeTrue();

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->getJson(route('bank-accounts.statement.receivable-candidates', [$context['bankAccount'], $movement['entry']]))
        ->assertOk()->assertJsonCount(2, 'candidates')->assertJsonPath('candidates.0.id', $near->id)
        ->assertJsonPath('candidates.1.id', $far->id)->assertJsonMissingPath('selected_candidate_id');
});

it('creates a receivable provision and links the current statement entry as its receipt', function () {
    $context = receivableSettlementContext();
    $movement = receivableMovement($context);
    $customer = Customer::query()->create([
        'wallet_id' => $context['wallet']->id, 'name' => 'Cliente do extrato', 'active' => true,
        'receivable_account_id' => $context['wallet']->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->value('id'),
        'default_revenue_account_id' => $context['revenue']->id,
    ]);
    $receivable = app(CreateAndLinkAccountReceivableFromBankStatement::class)->execute(
        $context['wallet'], $context['bankAccount'], $movement['entry'],
        new AccountReceivableDTO(0, '', 'Receita criada pelo extrato', '2026-07-10', 25_000, 'Criada no extrato', customerId: $customer->id),
    );
    expect($receivable->status)->toBe('received')->and($receivable->receipt_journal_entry_id)->toBe($movement['entry']->id)
        ->and($receivable->provision_journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and($movement['entry']->fresh('lines')->lines->contains('chart_of_account_id', $context['wallet']->suspense_account_id))->toBeFalse();
});

it('ignores a divergent date when creating a receivable from the statement endpoint', function () {
    $context = receivableSettlementContext(); $movement = receivableMovement($context);
    $customer = Customer::query()->create(['wallet_id' => $context['wallet']->id, 'name' => 'Cliente data fixa', 'active' => true,
        'receivable_account_id' => $context['wallet']->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->value('id'), 'default_revenue_account_id' => $context['revenue']->id]);
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])->post(route('bank-accounts.statement.create-link-receivable', [$context['bankAccount'], $movement['entry']]), [
        'customer_id' => $customer->id, 'description' => 'Data fixa', 'due_date' => '2030-01-01',
    ])->assertSessionHasNoErrors();
    $receivable = AccountReceivable::query()->sole();
    expect($receivable->due_date->toDateString())->toBe('2026-07-10')->and($receivable->received_at->toDateString())->toBe('2026-07-10')
        ->and($receivable->provisionJournalEntry->entry_date->toDateString())->toBe('2026-07-10');
});

it('links an explicitly selected receivable by reusing the draft and preserving the bank line', function () {
    $context = receivableSettlementContext();
    $movement = receivableMovement($context);
    $title = receivableTitle($context);
    $entryCount = JournalEntry::count();
    $lineCount = JournalLine::count();
    $originalBank = $movement['bankLine']->only(['id', 'journal_entry_id', 'chart_of_account_id', 'type', 'amount_cents', 'memo']);

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('bank-accounts.statement.link-receivable', [$context['bankAccount'], $movement['entry']]), ['account_receivable_id' => $title->id])
        ->assertOk()->assertJsonPath('account_receivable.status', 'received')
        ->assertJsonPath('account_receivable.received_at', '2026-07-10')
        ->assertJsonPath('account_receivable.receipt_journal_entry_id', $movement['entry']->id)
        ->assertJsonPath('journal_entry.status', 'draft')->assertJsonPath('journal_entry.ready_for_accounting', true);

    expect(JournalEntry::count())->toBe($entryCount)->and(JournalLine::count())->toBe($lineCount)
        ->and($movement['bankLine']->fresh()->only(array_keys($originalBank)))->toBe($originalBank)
        ->and($movement['entry']->fresh()->settledAccountReceivable?->id)->toBe($title->id);
    $this->assertDatabaseHas('journal_lines', ['id' => $movement['counterpart']->id, 'chart_of_account_id' => $title->receivable_account_id, 'type' => 'credit']);
    $this->assertDatabaseHas('bank_statement_import_transactions', ['id' => $movement['audit']->id, 'classification_account_id' => $title->receivable_account_id]);

    $statement = app(BankStatementService::class)->build($context['wallet'], new BankStatementFiltersDTO(
        bankAccountId: $context['bankAccount']->id, startDate: '2026-07-01', endDate: '2026-07-31',
    ))->transactions->first();
    expect($statement['workflow_status'])->toBe('ready_for_accounting')
        ->and($statement['linked_account_receivable']['id'])->toBe($title->id)
        ->and($statement['classification_account_id'])->toBe($title->receivable_account_id);

    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->get(route('accounting.pending-entries.index'))->assertOk();
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
        ->postJson(route('accounting.pending-entries.post-selected'), ['entry_ids' => [$movement['entry']->id]])
        ->assertOk();
    expect($movement['entry']->fresh()->status)->toBe('posted');
});

it('blocks invalid amount, received title, outgoing movement, other wallet and duplicate links', function () {
    $context = receivableSettlementContext();
    foreach ([
        [receivableMovement($context), receivableTitle($context, ['amount_cents' => 24999]), 'account_receivable_id'],
        [receivableMovement($context), receivableTitle($context, ['status' => 'received', 'received_at' => '2026-07-01']), 'account_receivable_id'],
        [receivableMovement($context, direction: 'out'), receivableTitle($context), 'journal_entry_id'],
    ] as [$movement, $title, $field]) {
        $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])
            ->postJson(route('bank-accounts.statement.link-receivable', [$context['bankAccount'], $movement['entry']]), ['account_receivable_id' => $title->id])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    $movement = receivableMovement($context);
    $first = receivableTitle($context);
    $second = receivableTitle($context);
    $route = route('bank-accounts.statement.link-receivable', [$context['bankAccount'], $movement['entry']]);
    $this->actingAs($context['user'])->withSession(['active_wallet' => $context['wallet']->id])->postJson($route, ['account_receivable_id' => $first->id])->assertOk();
    $this->postJson($route, ['account_receivable_id' => $second->id])->assertUnprocessable()->assertJsonValidationErrors('journal_entry_id');

    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->firstOrFail();
    $otherRevenue = AccountingTestHelper::account($otherWallet, '4.99.1', 'Receita externa', 'receita', 'credit');
    $other = AccountReceivable::query()->create(['wallet_id' => $otherWallet->id, 'receivable_account_id' => $otherWallet->chartOfAccounts()->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->value('id'), 'revenue_account_id' => $otherRevenue->id, 'customer_name' => 'Externo', 'description' => 'Externo', 'due_date' => '2026-07-10', 'amount_cents' => 25000, 'status' => 'pending']);
    $freshMovement = receivableMovement($context);
    $this->postJson(route('bank-accounts.statement.link-receivable', [$context['bankAccount'], $freshMovement['entry']]), ['account_receivable_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('account_receivable_id');
});
