<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\CashFlowFiltersDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Services\Financial\BuildCashFlow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashFlowController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, BuildCashFlow $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $rawFilters = [
            'start_date' => $request->query('start_date') ?: now()->toDateString(),
            'end_date' => $request->query('end_date') ?: now()->addDays(60)->toDateString(),
            'mode' => $request->query('mode', 'all'),
            'search' => $request->string('search')->toString(),
        ];

        $validated = validator($rawFilters, [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'mode' => ['required', Rule::in(['all', 'realized', 'projected'])],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $cashFlow = $service->handle($wallet, CashFlowFiltersDTO::fromArray($validated));

        return Inertia::render('Financial/CashFlow/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'filters' => $cashFlow['filters'],
            'summary' => $cashFlow['summary'],
            'items' => $cashFlow['items'],
        ]);
    }
}
