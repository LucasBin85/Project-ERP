<?php

namespace App\Http\Controllers;

use App\Services\Accounting\BuildFinancialPosition;
use Illuminate\Http\Request;
use Inertia\Response;

class FinancialPositionController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesActiveWallet;

    public function index(Request $request, BuildFinancialPosition $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $service->handle($wallet);
        //return dd($data);
        return inertia('FinancialPosition/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'position' => $data,
        ]);
    }
}