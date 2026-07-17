<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Models\ChartOfAccount;
use App\Models\Wallet;
use App\Models\Supplier;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChartOfAccountController extends Controller
{
    use ResolvesActiveWallet;

    public function index(Request $request): Response
    {
        $wallet = $this->resolveActiveWallet($request);

        $accounts = $wallet->chartOfAccounts()
            ->orderBy('code')
            ->get()
            ->map(function (ChartOfAccount $account) {
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'normal_balance' => $account->normal_balance,
                    'is_system' => $account->is_system,
                    'allows_posting' => $account->allows_posting,
                    'financial_group' => $account->financial_group,
                    'is_synthetic' => $account->isSynthetic(),
                    'parent_id' => $account->parent_id,
                ];
            });

        $buildTree = function ($items, $parentId = null) use (&$buildTree) {
            return collect($items)
                ->where('parent_id', $parentId)
                ->map(function ($item) use ($items, $buildTree) {
                    $item['children'] = $buildTree($items, $item['id']);
                    return $item;
                })
                ->values()
                ->all();
        };

        return Inertia::render('Accounting/ChartOfAccounts/Index', [
            'tree' => $buildTree($accounts),
            'activeWallet' => $wallet->id,
            'financialGroups' => ChartOfAccount::financialGroups(),
            'payableControlAccounts' => $this->postingAccounts($wallet, 'passivo', 'accounts_payable'),
            'expenseAccounts' => $this->postingAccounts($wallet, 'despesa'),
            'receivableControlAccounts' => $this->postingAccounts($wallet, 'ativo', 'accounts_receivable'),
            'revenueAccounts' => $this->postingAccounts($wallet, 'receita'),
            'supplierNames' => Supplier::query()->where('wallet_id', $wallet->id)->pluck('name'),
            'customerNames' => Customer::query()->where('wallet_id', $wallet->id)->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:ativo,passivo,receita,despesa,patrimonio'],
            'allows_posting' => ['required', 'boolean'],
            'financial_group' => ['nullable', 'in:' . implode(',', ChartOfAccount::financialGroups())],
        ]);

        $parent = null;

        if (! empty($data['parent_id'])) {
            $parent = $wallet->chartOfAccounts()->findOrFail($data['parent_id']);
        }

        if ($parent && $this->belongsToCounterpartyGroup($parent)) {
            throw ValidationException::withMessages([
                'allows_posting' => $parent->type === 'passivo'
                    ? 'Crie contas em Contas a Pagar pelo cadastro de Fornecedores / Contas a Pagar para gerar também a despesa padrão.'
                    : 'Crie contas em Contas a Receber pelo cadastro de Clientes / Contas a Receber para gerar também a receita padrão.',
            ]);
        }

        $type = $parent?->type ?? ($data['type'] ?? 'ativo');
        $normalBalance = ChartOfAccount::normalBalanceByType($type);

        $financialGroup = $data['financial_group'] ?? null;

        if ((bool) $data['allows_posting'] === true) {
            $financialGroup = null;
        }

        $code = $this->generateNextCode($wallet, $parent);

        $wallet->chartOfAccounts()->create([
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $data['name'],
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_system' => false,
            'allows_posting' => (bool) $data['allows_posting'],
            'financial_group' => $financialGroup,
        ]);

        return back(303)->with('success', 'Conta criada com sucesso.');
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $this->ensureAccountBelongsToWallet($wallet, $chartOfAccount);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allows_posting' => ['required', 'boolean'],
            'financial_group' => ['nullable', 'in:' . implode(',', ChartOfAccount::financialGroups())],
        ]);

        if ($chartOfAccount->isSystem()) {
            // Conta do sistema: edição limitada
            $chartOfAccount->update([
                'name' => $data['name'],
            ]);

            return back(303)->with('success', 'Conta do sistema atualizada com edição limitada.');
        }

        $financialGroup = $data['financial_group'] ?? null;

        if ((bool) $data['allows_posting'] === true) {
            $financialGroup = null;
        }

        $chartOfAccount->update([
            'name' => $data['name'],
            'allows_posting' => (bool) $data['allows_posting'],
            'financial_group' => $financialGroup,
        ]);

        return back(303)->with('success', 'Conta atualizada com sucesso.');
    }

    public function destroy(Request $request, ChartOfAccount $chartOfAccount): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $this->ensureAccountBelongsToWallet($wallet, $chartOfAccount);

        if ($chartOfAccount->isSystem()) {
            return back(303)->with('error', 'Contas do sistema não podem ser excluídas.');
        }

        if ($chartOfAccount->children()->exists()) {
            return back(303)->with('error', 'A conta possui subcontas e não pode ser excluída.');
        }

        if ($chartOfAccount->journalLines()->exists()) {
            return back(303)->with('error', 'A conta possui lançamentos e não pode ser excluída.');
        }

        $chartOfAccount->delete();

        return back(303)->with('success', 'Conta excluída com sucesso.');
    }

    protected function ensureAccountBelongsToWallet(Wallet $wallet, ChartOfAccount $chartOfAccount): void
    {
        abort_unless((int) $chartOfAccount->wallet_id === (int) $wallet->id, 404);
    }

    protected function generateNextCode(Wallet $wallet, ?ChartOfAccount $parent = null): string
    {
        if ($parent) {
            $lastChildCode = $wallet->chartOfAccounts()
                ->where('parent_id', $parent->id)
                ->orderByRaw('LENGTH(code) desc')
                ->orderByDesc('code')
                ->value('code');

            if (! $lastChildCode) {
                return "{$parent->code}.1";
            }

            $lastSegment = (int) Str::afterLast($lastChildCode, '.');
            $nextSegment = $lastSegment + 1;

            return "{$parent->code}.{$nextSegment}";
        }

        $lastRootCode = $wallet->chartOfAccounts()
            ->whereNull('parent_id')
            ->orderByRaw('LENGTH(code) desc')
            ->orderByDesc('code')
            ->value('code');

        return $lastRootCode ? (string) ((int) $lastRootCode + 1) : '1';
    }

    private function belongsToCounterpartyGroup(ChartOfAccount $account): bool
    {
        while ($account) {
            if (in_array($account->financial_group, ['accounts_payable', 'accounts_receivable'], true)) {
                return true;
            }

            $account = $account->parent;
        }

        return false;
    }

    private function postingAccounts(Wallet $wallet, string $type, ?string $financialGroup = null): array
    {
        return $wallet->chartOfAccounts()->where('type', $type)
            ->when($financialGroup, fn ($query) => $query->where('financial_group', $financialGroup))
            ->where('allows_posting', true)->whereDoesntHave('children')->orderBy('code')
            ->get(['id', 'code', 'name'])->toArray();
    }
}
