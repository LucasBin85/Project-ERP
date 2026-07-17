<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Services\Financial\CreateCustomer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomerController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request)
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/Customers/Index', ['wallet' => $wallet->only('id', 'name'), 'customers' => Customer::where('wallet_id', $wallet->id)->with(['receivableAccount:id,code,name', 'defaultRevenueAccount:id,code,name'])->orderBy('name')->get()]);
    }

    public function create(Request $request)
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/Customers/Form', $this->props($wallet->id));
    }

    public function edit(Request $request, Customer $customer)
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($customer->wallet_id === $wallet->id, 404);

        return Inertia::render('Financial/Customers/Form', $this->props($wallet->id) + ['customer' => $customer]);
    }

    public function store(Request $request, CreateCustomer $service)
    {
        $wallet = $this->resolveActiveWallet($request);
        $service->execute($wallet, $this->validated($request, $wallet->id, creating: true));

        return redirect()->route('customers.index')->with('success', 'Cliente cadastrado com sucesso.');
    }

    public function quickStore(Request $request, CreateCustomer $service): JsonResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $customer = $service->execute($wallet, $this->validated($request, $wallet->id, creating: true));
        $customer->load(['receivableAccount:id,code,name', 'defaultRevenueAccount:id,code,name']);

        return response()->json(['customer' => $customer], 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($customer->wallet_id === $wallet->id, 404);
        $customer->update($this->validated($request, $wallet->id, $customer->id));

        return redirect()->route('customers.index')->with('success', 'Cliente atualizado com sucesso.');
    }

    private function validated(Request $request, int $walletId, ?int $id = null, bool $creating = false): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('customers')->where('wallet_id', $walletId)->ignore($id)], 'document' => ['nullable', 'string', 'max:50'], 'receivable_account_id' => [$creating ? 'nullable' : 'required', Rule::exists('chart_of_accounts', 'id')->where('wallet_id', $walletId)->where('type', 'ativo')->where('financial_group', 'accounts_receivable')->where('allows_posting', true)], 'default_revenue_account_id' => [$creating ? 'nullable' : 'required', Rule::exists('chart_of_accounts', 'id')->where('wallet_id', $walletId)->where('type', 'receita')->where('allows_posting', true)], 'default_revenue_name' => ['nullable', 'string', 'max:255'], 'active' => ['boolean']]);
    }

    private function props(int $walletId): array
    {
        $map = fn ($query) => $query->orderBy('code')->get(['id', 'code', 'name']);

        return ['controlAccounts' => $map(ChartOfAccount::where('wallet_id', $walletId)->where('type', 'ativo')->where('financial_group', 'accounts_receivable')->where('allows_posting', true)->whereDoesntHave('children')), 'resultAccounts' => $map(ChartOfAccount::where('wallet_id', $walletId)->where('type', 'receita')->where('allows_posting', true)->whereDoesntHave('children'))];
    }
}
