<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\BankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('seeds the local bank catalog idempotently', function () {
    $this->seed(BankSeeder::class);
    $this->seed(BankSeeder::class);

    expect(Bank::query()->count())->toBe(19)
        ->and(Bank::query()->where('code', '001')->value('short_name'))->toBe('Banco do Brasil')
        ->and(Bank::query()->where('code', '260')->value('ispb'))->toBe('18236120')
        ->and(Bank::query()->where('active', true)->count())->toBe(19);
});

it('shows only active banks and accounts from the active wallet on the create page', function () {
    $user = User::factory()->create();
    $activeWallet = $user->wallets()->firstOrFail();
    $otherWallet = Wallet::query()->create([
        'user_id' => $user->id,
        'name' => 'Outra carteira',
    ]);

    $activeBank = Bank::query()->create([
        'code' => '001',
        'name' => 'Banco do Brasil S.A.',
        'short_name' => 'Banco do Brasil',
        'ispb' => '00000000',
        'active' => true,
    ]);

    Bank::query()->create([
        'code' => '999',
        'name' => 'Banco inativo',
        'short_name' => 'Inativo',
        'ispb' => '99999999',
        'active' => false,
    ]);

    $otherChartAccount = $otherWallet->chartOfAccounts()->where('code', '1.1.2')->firstOrFail();
    $otherChild = $otherWallet->chartOfAccounts()->create([
        'parent_id' => $otherChartAccount->id,
        'code' => '1.1.2.001',
        'name' => 'Conta de outra carteira',
        'type' => 'ativo',
        'normal_balance' => 'debit',
        'allows_posting' => true,
    ]);

    BankAccount::query()->create([
        'wallet_id' => $otherWallet->id,
        'chart_of_account_id' => $otherChild->id,
        'bank_id' => $activeBank->id,
        'name' => 'Conta de outra carteira',
        'bank_name' => $activeBank->short_name,
        'bank_code' => $activeBank->code,
        'account_type' => 'checking',
    ]);

    $this->actingAs($user)
        ->withSession(['active_wallet' => $activeWallet->id])
        ->get(route('bank-accounts.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Financial/BankAccounts/Create')
            ->has('banks', 1)
            ->where('banks.0.id', $activeBank->id)
            ->where('bankAccounts', []));
});

it('creates a bank account from the catalog and derives legacy bank fields', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = Bank::query()->create([
        'code' => '260',
        'name' => 'Nu Pagamentos S.A.',
        'short_name' => 'Nubank',
        'ispb' => '18236120',
        'active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withSession(['active_wallet' => $wallet->id])
        ->post(route('bank-accounts.store'), [
            'bank_id' => $bank->id,
            'name' => 'Conta principal',
            'bank_code' => '999',
            'bank_name' => 'Valor enviado pelo cliente',
            'agency' => '0001',
            'account_number' => '123456',
            'account_type' => 'checking',
            'opening_balance_cents' => 0,
        ]);

    $bankAccount = BankAccount::query()->sole();

    $response->assertRedirect(route('bank-accounts.show', $bankAccount));

    expect($bankAccount->wallet_id)->toBe($wallet->id)
        ->and($bankAccount->bank_id)->toBe($bank->id)
        ->and($bankAccount->bank_name)->toBe('Nubank')
        ->and($bankAccount->bank_code)->toBe('260')
        ->and($bankAccount->bank->is($bank))->toBeTrue()
        ->and($bankAccount->chartOfAccount->wallet_id)->toBe($wallet->id)
        ->and($bankAccount->chartOfAccount->name)->toBe('Conta principal');
});

it('rejects an inactive bank when creating an account', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = Bank::query()->create([
        'code' => '999',
        'name' => 'Banco inativo',
        'short_name' => 'Inativo',
        'ispb' => '99999999',
        'active' => false,
    ]);

    $this->actingAs($user)
        ->withSession(['active_wallet' => $wallet->id])
        ->from(route('bank-accounts.create'))
        ->post(route('bank-accounts.store'), [
            'bank_id' => $bank->id,
            'name' => 'Conta inválida',
            'account_type' => 'checking',
            'opening_balance_cents' => 0,
        ])
        ->assertRedirect(route('bank-accounts.create'))
        ->assertSessionHasErrors('bank_id');

    expect(BankAccount::query()->count())->toBe(0);
});

it('keeps legacy bank accounts without a catalog relation compatible', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $parent = $wallet->chartOfAccounts()->where('code', '1.1.2')->firstOrFail();
    $chartAccount = $wallet->chartOfAccounts()->create([
        'parent_id' => $parent->id,
        'code' => '1.1.2.001',
        'name' => 'Conta legada',
        'type' => 'ativo',
        'normal_balance' => 'debit',
        'allows_posting' => true,
    ]);

    $legacy = BankAccount::query()->create([
        'wallet_id' => $wallet->id,
        'chart_of_account_id' => $chartAccount->id,
        'name' => 'Conta legada',
        'bank_name' => 'Banco legado',
        'bank_code' => '123',
        'account_type' => 'checking',
    ]);

    expect($legacy->bank_id)->toBeNull()
        ->and($legacy->bank)->toBeNull()
        ->and($legacy->bank_name)->toBe('Banco legado');
});

it('rejects a duplicate of a legacy account identified by bank code', function () {
    $user = User::factory()->create();
    $wallet = $user->wallets()->firstOrFail();
    $bank = Bank::query()->create([
        'code' => '123',
        'name' => 'Banco do Catálogo',
        'short_name' => 'Banco 123',
        'ispb' => '12345678',
        'active' => true,
    ]);
    $parent = $wallet->chartOfAccounts()->where('code', '1.1.2')->firstOrFail();
    $chartAccount = $wallet->chartOfAccounts()->create([
        'parent_id' => $parent->id,
        'code' => '1.1.2.999',
        'name' => 'Conta legada existente',
        'type' => 'ativo',
        'normal_balance' => 'debit',
        'allows_posting' => true,
    ]);
    BankAccount::query()->create([
        'wallet_id' => $wallet->id,
        'chart_of_account_id' => $chartAccount->id,
        'bank_id' => null,
        'name' => 'Conta legada existente',
        'bank_name' => 'Banco 123',
        'bank_code' => '000123',
        'agency' => '0001',
        'account_number' => '987654',
        'account_type' => 'checking',
    ]);

    $this->actingAs($user)
        ->withSession(['active_wallet' => $wallet->id])
        ->from(route('bank-accounts.create'))
        ->post(route('bank-accounts.store'), [
            'bank_id' => $bank->id,
            'name' => 'Tentativa duplicada',
            'agency' => '0001',
            'account_number' => '987654',
            'account_type' => 'checking',
            'opening_balance_cents' => 0,
        ])
        ->assertRedirect(route('bank-accounts.create'))
        ->assertSessionHasErrors('account_number');

    expect(BankAccount::query()->count())->toBe(1);
});
