<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Services\Accounting\IncomeStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IncomeStatementController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, IncomeStatementService $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        return Inertia::render('Accounting/IncomeStatement/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $filters,
            'incomeStatement' => $service->build(
                $wallet,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
            ),
        ]);
    }
}