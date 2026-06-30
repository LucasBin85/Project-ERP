<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrialBalanceController extends Controller
{
    use ResolvesActiveWallet;

    public function index(
        Request $request,
        TrialBalanceService $service
    ): Response {
        $wallet = $this->resolveActiveWallet($request);

        $startDate = $request->query('start_date')
            ?: now()->startOfYear()->toDateString();

        $endDate = $request->query('end_date')
            ?: now()->toDateString();
            
        return Inertia::render('Accounting/TrialBalance/Index', [
            'wallet' => $wallet,
            'trialBalance' => $service->generate(
                wallet: $wallet,
                from: $startDate,
                to: $endDate,
            ),
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}