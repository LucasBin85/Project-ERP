<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Services\Financial\CreateBankAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $accounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->with('chartOfAccount')
            ->orderBy('name')
            ->get();

        return Inertia::render('Financial/BankAccounts/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $accounts,
        ]);
    }

    public function create(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->get([
                'id',
                'name',
                'bank_code',
                'agency',
                'account_number',
            ]);

        return Inertia::render('Financial/BankAccounts/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function store(Request $request, CreateBankAccount $service)
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('bank_accounts', 'name')
                    ->where('wallet_id', $wallet->id),
            ],

            'bank_code' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9]*$/',
            ],

            'agency' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[0-9]*$/',
            ],

            'account_number' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[0-9]*$/',
                Rule::unique('bank_accounts', 'account_number')
                    ->where('wallet_id', $wallet->id)
                    ->where('bank_code', $request->bank_code)
                    ->where('agency', $request->agency),
            ],

            'account_type' => [
                'required',
                Rule::in([
                    'checking',
                    'savings',
                    'investment',
                    'cash',
                    'other',
                ]),
            ],

            'opening_balance_cents' => ['nullable', 'integer', 'min:0'],

            'opening_balance_date' => [
                Rule::requiredIf(fn () => (int) $request->input('opening_balance_cents', 0) > 0),
                'nullable',
                'date',
            ],
        ]);

        $data['bank_name'] = $data['name'];

        $bankAccount = $service->execute($wallet, $data);

        return redirect()
            ->route('bank-accounts.index')
            ->with('success', 'Conta bancária criada com sucesso.');
    }
}