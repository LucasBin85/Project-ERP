<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Services\Financial\BuildManagerialFinancialDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, BuildManagerialFinancialDashboard $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = validator(['year' => $request->input('year', now()->year), 'month' => $request->input('month', now()->month)], [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();

        return Inertia::render('Dashboard/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'dashboard' => $service->execute($wallet, (int) $data['year'], (int) $data['month']),
        ]);
    }
}
