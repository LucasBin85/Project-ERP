<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\WalletController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialPositionController;
use App\Http\Controllers\Accounting\ChartOfAccountController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\GeneralJournalController;
use App\Http\Controllers\Accounting\LedgerController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\Accounting\IncomeStatementController;
use App\Http\Controllers\Accounting\BalanceSheetController;
use App\Http\Controllers\Financial\BankAccountController;
use App\Http\Controllers\Financial\BankTransferController;
use App\Http\Controllers\Financial\BankStatementController;
use App\Http\Controllers\Financial\BankReconciliationController;
use App\Http\Controllers\Financial\AccountPayableController;
use App\Http\Controllers\Financial\AccountReceivableController;
use App\Http\Controllers\Financial\CreditCardController;
use App\Http\Controllers\Financial\CashFlowController;
use App\Http\Controllers\Financial\OfxImportController;



Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');
    // CRUD de carteiras
    Route::resource('wallets', WalletController::class)
        ->only(['index','create','store', 'show','edit','update','destroy'])
        ->names('wallets');
    // Ativar carteira
    // (define a sessão com a carteira ativa)
    Route::post('/wallets/active', [WalletController::class, 'setActive'])
        ->name('wallets.active');

    Route::resource('chart-of-accounts', ChartOfAccountController::class)
        ->only(['index','create','store', 'show','edit','update','destroy'])
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
        Route::resource('bank-accounts', BankAccountController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::resource('bank-transfers', BankTransferController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::get('bank-statements', [BankStatementController::class, 'index'])
            ->name('bank-statements.index');

        Route::get('cash-flow', [CashFlowController::class, 'index'])
            ->name('cash-flow.index');

        Route::get('ofx-imports', [OfxImportController::class, 'index'])
            ->name('ofx-imports.index');

        Route::post('ofx-imports', [OfxImportController::class, 'store'])
            ->name('ofx-imports.store');

        Route::resource('bank-reconciliations', BankReconciliationController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::post('accounts-payable/{accountPayable}/pay', [AccountPayableController::class, 'pay'])
            ->name('accounts-payable.pay');

        Route::resource('accounts-payable', AccountPayableController::class)
            ->only(['index', 'create', 'store', 'show']);

        Route::post('accounts-receivable/{accountReceivable}/receive', [AccountReceivableController::class, 'receive'])
            ->name('accounts-receivable.receive');

        Route::resource('accounts-receivable', AccountReceivableController::class)
            ->only(['index', 'create', 'store', 'show']);

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
