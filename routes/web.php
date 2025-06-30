<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\WalletController;
use App\Http\Controllers\ChartOfAccountController;

Route::middleware(['auth', 'verified'])->group(function () {
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
});

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


Route::get('/check-auth', function () {
    return Auth::user();
});


require __DIR__.'/settings.php';
require __DIR__.'/auth.php';


