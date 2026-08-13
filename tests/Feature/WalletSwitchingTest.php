<?php

use App\Models\CreditCard;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('creates a wallet, activates it and redirects to a valid dashboard', function () {
    $user = User::factory()->create();
    $previous = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Carteira anterior']);

    $response = $this->actingAs($user)
        ->withSession(['active_wallet' => $previous->id])
        ->post(route('wallets.store'), ['name' => 'Nova carteira']);

    $wallet = Wallet::query()->where('user_id', $user->id)->where('name', 'Nova carteira')->firstOrFail();
    $response->assertRedirect(route('dashboard'))->assertSessionHas('active_wallet', $wallet->id);
    expect($wallet->suspense_account_id)->not->toBeNull();

    $this->actingAs($user)->withSession(['active_wallet' => $wallet->id])->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.active_wallet', $wallet->id)
            ->has('auth.user.wallets', 3));
});

it('switches wallets through a safe dashboard and blocks stale scoped resources', function () {
    $user = User::factory()->create();
    $walletA = Wallet::query()->create(['user_id' => $user->id, 'name' => 'A']);
    $walletB = Wallet::query()->create(['user_id' => $user->id, 'name' => 'B']);
    $cardA = CreditCard::query()->create([
        'wallet_id' => $walletA->id, 'liability_account_id' => $walletA->suspense_account_id,
        'name' => 'Cartão A', 'issuer_name' => 'Banco A', 'network' => 'other', 'card_type' => 'main',
        'closing_day' => 1, 'due_day' => 8, 'best_purchase_day' => 2,
        'credit_limit_cents' => 100000, 'is_active' => true,
    ]);

    $this->actingAs($user)->withSession(['active_wallet' => $walletA->id])
        ->post(route('wallets.active'), ['wallet_id' => $walletB->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('active_wallet', $walletB->id);

    $this->actingAs($user)->withSession(['active_wallet' => $walletB->id])
        ->get(route('credit-cards.show', $cardA))
        ->assertNotFound();

    $this->actingAs($user)->withSession(['active_wallet' => $walletB->id])->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.active_wallet', $walletB->id));
});

it('rejects switching to another users wallet without leaking its existence', function () {
    $user = User::factory()->create();
    $own = Wallet::query()->create(['user_id' => $user->id, 'name' => 'Própria']);
    $foreign = Wallet::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Alheia']);

    $this->actingAs($user)->withSession(['active_wallet' => $own->id])
        ->post(route('wallets.active'), ['wallet_id' => $foreign->id])
        ->assertNotFound();
});
