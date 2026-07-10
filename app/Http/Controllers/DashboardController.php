<?php

namespace App\Http\Controllers;

use App\DTOs\Financial\DashboardFiltersDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Services\Financial\BuildFinancialDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, BuildFinancialDashboard $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $filters = DashboardFiltersDTO::fromArray([
            'start_date' => $request->input('start_date') ?: now()->startOfMonth()->toDateString(),
            'end_date' => $request->input('end_date') ?: now()->toDateString(),
        ]);

        $dashboard = $service->handle($wallet, $filters);

        return Inertia::render('Dashboard/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            ...$dashboard,
        ]);
    }
}
