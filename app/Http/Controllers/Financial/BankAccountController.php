<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankAccountDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Services\Financial\BuildBankAccountWorkspace;
use App\Services\Financial\CreateBankAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class BankAccountController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, BuildBankAccountWorkspace $workspace): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $workspace->index($wallet);

        return Inertia::render('Financial/BankAccounts/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $data['accounts'],
            'summary' => $data['summary'],
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
                'bank_id',
                'bank_code',
                'agency',
                'account_number',
            ]);

        $banks = Bank::query()
            ->where('active', true)
            ->orderBy('short_name')
            ->get([
                'id',
                'code',
                'name',
                'short_name',
                'ispb',
            ]);

        return Inertia::render('Financial/BankAccounts/Create', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $bankAccounts,
            'banks' => $banks,
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

            'bank_id' => [
                'required',
                'integer',
                Rule::exists('banks', 'id')->where('active', true),
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
                    ->where('bank_id', $request->integer('bank_id'))
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

        try {
            $bankAccount = $service->execute($wallet, BankAccountDTO::fromArray($data));
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withErrors(['account_number' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('bank-accounts.show', $bankAccount)
            ->with('success', 'Conta bancária criada com sucesso.');
    }

    public function show(Request $request, BankAccount $bankAccount, BuildBankAccountWorkspace $workspace): Response
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $workspace->show($wallet, $bankAccount);

        return Inertia::render('Financial/BankAccounts/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            ...$data,
        ]);
    }
}
