<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Services\Financial\CreateInvestmentAccount;
use App\Services\Financial\OfxOperationTypePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InvestmentAccountController extends Controller
{
    use ResolvesActiveWallet;

    public function quickStore(Request $request, CreateInvestmentAccount $service): JsonResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        try {
            $account = $service->execute($wallet, $data['name']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return response()->json(['account' => [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'financial_group' => $account->financial_group,
            'allowed_operation_types' => [OfxOperationTypePolicy::INVESTMENT],
            'bank_account' => null,
        ]], 201);
    }
}
