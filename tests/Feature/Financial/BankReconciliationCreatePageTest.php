<?php

use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImport;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Helpers\AccountingTestHelper;
use Tests\Helpers\FinancialTestHelper;

uses(RefreshDatabase::class);

it('requires authentication and exposes the create route', function () {
    expect(\Illuminate\Support\Facades\Route::has('bank-reconciliations.create'))->toBeTrue();

    $this->get(route('bank-reconciliations.create'))
        ->assertRedirect(route('login'));
});

it('renders create with active wallet bank accounts and no invented official balance', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira principal']);
    $otherWallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira secundária']);
    $account = FinancialTestHelper::bankAccount($wallet, '1.1.2.971', 'Banco principal');
    $foreignAccount = FinancialTestHelper::bankAccount($otherWallet, '1.1.2.972', 'Banco externo');

    $this->actingAs($user)
        ->withSession(['active_wallet' => $wallet->id])
        ->get(route('bank-reconciliations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Financial/BankReconciliations/Create')
            ->where('wallet.id', $wallet->id)
            ->has('bankAccounts', 1)
            ->where('bankAccounts.0.id', $account->id)
            ->where('initial.bank_account_id', null)
            ->where('initial.period_start', null)
            ->where('initial.period_end', null)
            ->where('initial.statement_balance_cents', null)
            ->has('initial.statement_items', 0));

    expect($foreignAccount->exists)->toBeTrue();
});

it('prefills contextual account and period with canonical imported items and keeps GET read only', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira contextual']);
    $bankAccount = FinancialTestHelper::bankAccount($wallet, '1.1.2.973', 'Banco contextual');
    $counterpart = AccountingTestHelper::account($wallet, '3.973', 'Contrapartida', 'patrimonio', 'credit');
    $entry = AccountingTestHelper::createPostedEntry($wallet, '2026-07-10', [
        [$bankAccount->chartOfAccount, 'debit', 12345],
        [$counterpart, 'credit', 12345],
    ]);
    $line = $entry->lines->firstWhere('chart_of_account_id', $bankAccount->chart_of_account_id);
    $import = BankStatementImport::query()->create([
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'source' => 'ofx',
        'original_filename' => 'julho.ofx',
        'file_hash' => hash('sha256', 'julho-contextual'),
        'status' => 'completed',
    ]);
    $transaction = BankStatementImportTransaction::query()->create([
        'bank_statement_import_id' => $import->id,
        'wallet_id' => $wallet->id,
        'bank_account_id' => $bankAccount->id,
        'journal_entry_id' => $entry->id,
        'journal_line_id' => $line->id,
        'external_id' => 'contextual-001',
        'transaction_hash' => hash('sha256', 'contextual-001'),
        'fit_id' => 'FIT-CONTEXTUAL-001',
        'posted_at' => '2026-07-10',
        'description' => 'Recebimento importado',
        'amount_cents' => 12345,
        'direction' => 'in',
        'status' => 'imported',
        'resolution' => 'created',
    ]);
    $counts = [
        BankReconciliation::query()->count(),
        BankReconciliationItem::query()->count(),
        BankReconciliationStatementItem::query()->count(),
        BankStatementImport::query()->count(),
        BankStatementImportTransaction::query()->count(),
        JournalEntry::query()->count(),
    ];
    $entrySnapshot = $entry->fresh()->getAttributes();

    $this->actingAs($user)
        ->withSession(['active_wallet' => $wallet->id])
        ->get(route('bank-reconciliations.create', [
            'bank_account_id' => $bankAccount->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Financial/BankReconciliations/Create')
            ->where('initial.bank_account_id', $bankAccount->id)
            ->where('initial.period_start', '2026-07-01')
            ->where('initial.period_end', '2026-07-31')
            ->where('initial.statement_balance_cents', null)
            ->has('initial.statement_items', 1)
            ->where('initial.statement_items.0.bank_statement_import_transaction_id', $transaction->id)
            ->where('initial.statement_items.0.source_label', 'Extrato importado')
            ->where('initial.statement_items.0.amount_cents', 12345)
            ->where('initial.statement_items.0.journal_line_id', $line->id));

    expect([
        BankReconciliation::query()->count(),
        BankReconciliationItem::query()->count(),
        BankReconciliationStatementItem::query()->count(),
        BankStatementImport::query()->count(),
        BankStatementImportTransaction::query()->count(),
        JournalEntry::query()->count(),
    ])->toBe($counts)
        ->and($entry->fresh()->getAttributes())->toBe($entrySnapshot);
});

it('returns not found for a contextual bank account outside the active wallet', function () {
    $user = User::factory()->create();
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira ativa']);
    $otherWallet = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira externa']);
    $foreignAccount = FinancialTestHelper::bankAccount($otherWallet, '1.1.2.974', 'Banco externo');

    $this->actingAs($user)
        ->withSession(['active_wallet' => $wallet->id])
        ->get(route('bank-reconciliations.create', [
            'bank_account_id' => $foreignAccount->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]))
        ->assertNotFound();
});

it('exposes the shared creation flow from reconciliation history and bank statement', function () {
    $createPage = file_get_contents(resource_path('js/pages/Financial/BankReconciliations/Create.vue'));
    $historyPage = file_get_contents(resource_path('js/pages/Financial/BankReconciliations/Index.vue'));
    $statementPage = file_get_contents(resource_path('js/pages/Financial/BankStatements/Index.vue'));

    expect($historyPage)->toContain("route('bank-reconciliations.create')", 'Nova conciliação')
        ->and($statementPage)->toContain("route('bank-reconciliations.create'", 'bank_account_id:', 'period_start:', 'period_end:', 'Conciliar período')
        ->and($createPage)->toContain(
            "route('bank-reconciliations.preview')",
            "route('bank-reconciliations.store')",
            'previewSignature',
            'has_existing_overlap',
            'Ver conciliação existente',
            'Salvar conciliação',
        );
});
