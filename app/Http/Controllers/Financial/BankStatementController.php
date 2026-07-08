<?php

namespace App\Http\Controllers\Financial;

use App\DTOs\Financial\BankStatementFiltersDTO;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Services\Financial\BankStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankStatementController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request, BankStatementService $service): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $rawFilters = [
            'bank_account_id' => $request->string('bank_account_id')->toString(),
            'start_date' => $request->query('start_date') ?: now()->startOfMonth()->toDateString(),
            'end_date' => $request->query('end_date') ?: now()->toDateString(),
            'search' => $request->string('search')->toString(),
        ];

        $validated = validator($rawFilters, [
            'bank_account_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                function (string $attribute, mixed $value, \Closure $fail) use ($rawFilters) {
                    if (substr((string) $rawFilters['start_date'], 0, 7) !== substr((string) $value, 0, 7)) {
                        $fail('O período do extrato deve estar dentro do mesmo mês.');
                    }
                },
            ],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $filters = BankStatementFiltersDTO::fromArray($validated);
        $statement = $service->build($wallet, $filters)->toArray();

        $bankAccounts = BankAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'bank_name',
                'bank_code',
                'agency',
                'account_number',
                'account_type',
            ])
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $this->formatBankAccountLabel($account),
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'bank_code' => $account->bank_code,
                'agency' => $account->agency,
                'account_number' => $account->account_number,
                'account_type' => $account->account_type,
            ])
            ->values();

        return Inertia::render('Financial/BankStatements/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
            ],
            'bankAccounts' => $bankAccounts,
            'filters' => $statement['filters'],
            'statementReady' => $statement['ready'],
            'selectedBankAccount' => $statement['bank_account'],
            'summary' => $statement['summary'],
            'transactions' => $statement['transactions'],
        ]);
    }

    private function formatBankAccountLabel(BankAccount $account): string
    {
        $details = collect([
            $account->bank_code,
            $account->agency,
            $account->account_number,
        ])->filter()->join(' / ');

        return $details !== ''
            ? "{$account->name} ({$details})"
            : $account->name;
    }
}
