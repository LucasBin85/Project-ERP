<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\Supplier;
use App\Services\Financial\CreateSupplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupplierController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request)
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/Suppliers/Index', ['wallet' => $wallet->only('id', 'name'), 'suppliers' => Supplier::where('wallet_id', $wallet->id)->with(['payableAccount:id,code,name', 'defaultExpenseAccount:id,code,name'])->orderBy('name')->get()]);
    }

    public function create(Request $request)
    {
        $wallet = $this->resolveActiveWallet($request);

        return Inertia::render('Financial/Suppliers/Form', $this->props($wallet->id));
    }

    public function edit(Request $request, Supplier $supplier)
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($supplier->wallet_id === $wallet->id, 404);

        return Inertia::render('Financial/Suppliers/Form', $this->props($wallet->id) + ['supplier' => $supplier]);
    }

    public function store(Request $request, CreateSupplier $service)
    {
        $wallet = $this->resolveActiveWallet($request);
        $service->execute($wallet, $this->validated($request, $wallet->id, creating: true));

        return redirect()->route('suppliers.index')->with('success', 'Fornecedor cadastrado com sucesso.');
    }

    public function update(Request $request, Supplier $supplier)
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless($supplier->wallet_id === $wallet->id, 404);
        $supplier->update($this->validated($request, $wallet->id, $supplier->id));

        return redirect()->route('suppliers.index')->with('success', 'Fornecedor atualizado com sucesso.');
    }

    private function validated(Request $request, int $walletId, ?int $id = null, bool $creating = false): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('suppliers')->where('wallet_id', $walletId)->ignore($id)], 'document' => ['nullable', 'string', 'max:50'], 'payable_account_id' => [$creating ? 'nullable' : 'required', Rule::exists('chart_of_accounts', 'id')->where('wallet_id', $walletId)->where('type', 'passivo')->where('financial_group', 'accounts_payable')->where('allows_posting', true)], 'default_expense_account_id' => [$creating ? 'nullable' : 'required', Rule::exists('chart_of_accounts', 'id')->where('wallet_id', $walletId)->where('type', 'despesa')->where('allows_posting', true)], 'default_expense_name' => ['nullable', 'string', 'max:255'], 'active' => ['boolean']]);
    }

    private function props(int $walletId): array
    {
        $map = fn ($query) => $query->orderBy('code')->get(['id', 'code', 'name']);

        return ['controlAccounts' => $map(ChartOfAccount::where('wallet_id', $walletId)->where('type', 'passivo')->where('financial_group', 'accounts_payable')->where('allows_posting', true)->whereDoesntHave('children')), 'resultAccounts' => $map(ChartOfAccount::where('wallet_id', $walletId)->where('type', 'despesa')->where('allows_posting', true)->whereDoesntHave('children'))];
    }
}
