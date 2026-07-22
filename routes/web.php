<?php

use App\Http\Controllers\Accounting\BalanceSheetController;
use App\Http\Controllers\Accounting\ChartOfAccountController;
use App\Http\Controllers\Accounting\GeneralJournalController;
use App\Http\Controllers\Accounting\IncomeStatementController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\LedgerController;
use App\Http\Controllers\Accounting\PendingJournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Financial\AccountPayableController;
use App\Http\Controllers\Financial\AccountReceivableController;
use App\Http\Controllers\Financial\BankAccountController;
use App\Http\Controllers\Financial\BankReconciliationController;
use App\Http\Controllers\Financial\BankStatementController;
use App\Http\Controllers\Financial\BankStatementSettlementController;
use App\Http\Controllers\Financial\BankStatementClassificationRuleController;
use App\Http\Controllers\Financial\BankStatementClosingController;
use App\Http\Controllers\Financial\BankTransferController;
use App\Http\Controllers\Financial\CashFlowController;
use App\Http\Controllers\Financial\CreditCardController;
use App\Http\Controllers\Financial\SupplierController;
use App\Http\Controllers\Financial\CustomerController;
use App\Http\Controllers\Financial\OfxImportController;
use App\Http\Controllers\Financial\InvestmentAccountController;
use App\Http\Controllers\FinancialPositionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');
    // CRUD de carteiras
    Route::resource('wallets', WalletController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->names('wallets');
    // Ativar carteira
    // (define a sessão com a carteira ativa)
    Route::post('/wallets/active', [WalletController::class, 'setActive'])
        ->name('wallets.active');

    Route::resource('chart-of-accounts', ChartOfAccountController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->names('chart-of-accounts');

    Route::get('/Accounting/journal-entries', [JournalEntryController::class, 'index'])
        ->name('journal-entries.index');

    Route::get('/Accounting/journal-entries/create', [JournalEntryController::class, 'create'])
        ->name('journal-entries.create');

    Route::post('/Accounting/journal-entries', [JournalEntryController::class, 'store'])
        ->name('journal-entries.store');

    Route::get('/Accounting/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])
        ->name('journal-entries.show');

    Route::post('/Accounting/journal-entries/{journalEntry}/reclassify', [JournalEntryController::class, 'reclassify'])
        ->name('journal-entries.reclassify');

    Route::post('/Accounting/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])
        ->name('journal-entries.post');

    Route::get('/accounting/pending-entries', [PendingJournalEntryController::class, 'index'])
        ->name('accounting.pending-entries.index');

    Route::post('/accounting/pending-entries/post-selected', [PendingJournalEntryController::class, 'postSelected'])
        ->name('accounting.pending-entries.post-selected');

    Route::post('/accounting/pending-entries/post-all', [PendingJournalEntryController::class, 'postAll'])
        ->name('accounting.pending-entries.post-all');

    Route::get('/general-journal', [GeneralJournalController::class, 'index'])
        ->name('general-journal.index');

    Route::get('/ledger', [LedgerController::class, 'index'])
        ->name('ledger.index');

    Route::get('/financial-position', [FinancialPositionController::class, 'index'])
        ->name('financial-position.index');

    Route::get('/trial-balance', [TrialBalanceController::class, 'index'])
        ->name('trial-balance.index');

    Route::get('/income-statement', [IncomeStatementController::class, 'index'])
        ->name('income-statement.index');

    Route::get('/balance-sheet', [BalanceSheetController::class, 'index'])
        ->name('balance-sheet.index');

    Route::prefix('financial')->group(function () {
        Route::get('bank-accounts/{bankAccount}/statement', [BankStatementController::class, 'show'])
            ->name('bank-accounts.statement');
        Route::get('bank-accounts/{bankAccount}/closing', [BankStatementClosingController::class, 'show'])->name('bank-accounts.closing.show');
        Route::post('bank-accounts/{bankAccount}/closing/apply-suggestions', [BankStatementClosingController::class, 'applySuggestions'])->name('bank-accounts.closing.apply-suggestions');
        Route::post('bank-accounts/{bankAccount}/closing/post-ready', [BankStatementClosingController::class, 'postReady'])->name('bank-accounts.closing.post-ready');

        Route::post('bank-accounts/{bankAccount}/statement/bulk-post', [BankStatementController::class, 'bulkPost'])
            ->name('bank-accounts.statement.bulk-post');
        Route::post('bank-accounts/{bankAccount}/statement/bulk-apply-suggestions', [BankStatementController::class, 'bulkApplySuggestions'])
            ->name('bank-accounts.statement.bulk-apply-suggestions');

        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/classify', [BankStatementController::class, 'classify'])
            ->name('bank-accounts.statement.classify');
        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/apply-suggestion', [BankStatementController::class, 'applySuggestion'])
            ->name('bank-accounts.statement.apply-suggestion');

        Route::post('bank-statement-classification-rules', [BankStatementClassificationRuleController::class, 'store'])->name('bank-statement-classification-rules.store');
        Route::put('bank-statement-classification-rules/{classificationRule}', [BankStatementClassificationRuleController::class, 'update'])->name('bank-statement-classification-rules.update');
        Route::delete('bank-statement-classification-rules/{classificationRule}', [BankStatementClassificationRuleController::class, 'destroy'])->name('bank-statement-classification-rules.destroy');

        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/resolve-match', [BankStatementController::class, 'resolveMatch'])
            ->name('bank-accounts.statement.resolve-match');

        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/merge-transfer', [BankStatementController::class, 'mergeTransfer'])
            ->name('bank-accounts.statement.merge-transfer');

        Route::post('investment-accounts/quick-store', [InvestmentAccountController::class, 'quickStore'])
            ->name('investment-accounts.quick-store');

        Route::get('bank-accounts/{bankAccount}/statement/{journalEntry}/payable-candidates', [BankStatementSettlementController::class, 'payableCandidates'])
            ->name('bank-accounts.statement.payable-candidates');

        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/link-payable', [BankStatementSettlementController::class, 'linkPayable'])
            ->name('bank-accounts.statement.link-payable');

        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/create-link-payable', [BankStatementSettlementController::class, 'createAndLinkPayable'])
            ->name('bank-accounts.statement.create-link-payable');

        Route::get('bank-accounts/{bankAccount}/statement/{journalEntry}/receivable-candidates', [BankStatementSettlementController::class, 'receivableCandidates'])
            ->name('bank-accounts.statement.receivable-candidates');

        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/link-receivable', [BankStatementSettlementController::class, 'linkReceivable'])
            ->name('bank-accounts.statement.link-receivable');

        Route::post('bank-accounts/{bankAccount}/statement/{journalEntry}/create-link-receivable', [BankStatementSettlementController::class, 'createAndLinkReceivable'])
            ->name('bank-accounts.statement.create-link-receivable');

        Route::post('bank-accounts/ofx-preview', [BankAccountController::class, 'previewOfx'])
            ->name('bank-accounts.ofx-preview');

        Route::resource('bank-accounts', BankAccountController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update']);

        Route::resource('bank-transfers', BankTransferController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::get('cash-flow', [CashFlowController::class, 'index'])
            ->name('cash-flow.index');

        Route::get('ofx-imports', [OfxImportController::class, 'index'])
            ->name('ofx-imports.index');

        Route::post('ofx-imports/preview', [OfxImportController::class, 'preview'])
            ->name('ofx-imports.preview');

        Route::post('ofx-imports/confirm', [OfxImportController::class, 'confirm'])
            ->name('ofx-imports.confirm');

        Route::resource('bank-reconciliations', BankReconciliationController::class)
            ->only(['index', 'show']);

        Route::post('accounts-payable/{accountPayable}/pay', [AccountPayableController::class, 'pay'])
            ->name('accounts-payable.pay');

        Route::resource('accounts-payable', AccountPayableController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::post('accounts-receivable/{accountReceivable}/receive', [AccountReceivableController::class, 'receive'])
            ->name('accounts-receivable.receive');

        Route::resource('accounts-receivable', AccountReceivableController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::post('suppliers/quick-store', [SupplierController::class, 'quickStore'])->name('suppliers.quick-store');
        Route::post('customers/quick-store', [CustomerController::class, 'quickStore'])->name('customers.quick-store');
        Route::resource('suppliers', SupplierController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::resource('customers', CustomerController::class)->only(['index', 'create', 'store', 'edit', 'update']);

        Route::post('credit-cards/{creditCard}/transactions', [CreditCardController::class, 'storeTransaction'])
            ->name('credit-cards.transactions.store');

        Route::post('credit-cards/{creditCard}/payments', [CreditCardController::class, 'payInvoice'])
            ->name('credit-cards.payments.store');

        Route::resource('credit-cards', CreditCardController::class)
            ->only(['index', 'create', 'store', 'show']);
    });

});

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');
/*
Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');
*/

Route::get('/check-auth', function () {
    return Auth::user();
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
