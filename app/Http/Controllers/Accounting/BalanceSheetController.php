<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Services\Accounting\BalanceSheetService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BalanceSheetController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, BalanceSheetService $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $filters = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        return Inertia::render('Accounting/BalanceSheet/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $filters,
            'balanceSheet' => $service->build(
                wallet: $wallet,
                referenceDate: $filters['date'] ?? null,
            )->toArray(),
        ]);
    }
}