<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\WalletController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\GeneralJournalController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialPositionController;


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

    

    Route::get('/journal-entries', [JournalEntryController::class, 'index'])
        ->name('journal-entries.index');

    Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])
        ->name('journal-entries.show');

    Route::post('/journal-entries/{journalEntry}/reclassify', [JournalEntryController::class, 'reclassify'])
        ->name('journal-entries.reclassify');

    Route::post('/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])
        ->name('journal-entries.post');


    
    Route::get('/general-journal', [GeneralJournalController::class, 'index'])
        ->name('general-journal.index');



    Route::get('/ledger', [LedgerController::class, 'index'])
    ->name('ledger.index');


    Route::get('/financial-position', [FinancialPositionController::class, 'index'])
        ->name('financial-position.index');

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


