<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Wallet;
use Illuminate\Http\Request;

trait ResolvesActiveWallet
{
    protected function resolveActiveWallet(Request $request): Wallet
    {
        $user = $request->user();

        return $user
            ->wallets()
            ->findOrFail(session('active_wallet', $user->wallets()->first()->id));
    }
}