<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Concerns\ResolvesActiveWallet;
use App\Http\Controllers\Controller;
use App\Models\BankStatementClassificationRule;
use App\Services\Financial\OfxOperationTypePolicy;
use App\Services\Financial\ValidateBankStatementClassificationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankStatementClassificationRuleController extends Controller
{
    use ResolvesActiveWallet;

    public function store(Request $request, ValidateBankStatementClassificationRule $validator, OfxOperationTypePolicy $policy): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        $data = $this->validated($request, $policy);
        $validator->validate($wallet, $data);
        $wallet->classificationRules()->create($data);
        return back()->with('success', 'Regra de classificação criada com sucesso.');
    }

    public function update(Request $request, BankStatementClassificationRule $classificationRule, ValidateBankStatementClassificationRule $validator, OfxOperationTypePolicy $policy): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $classificationRule->wallet_id === (int) $wallet->id, 404);
        $data = $this->validated($request, $policy);
        $validator->validate($wallet, $data);
        $classificationRule->update($data);
        return back()->with('success', 'Regra de classificação atualizada.');
    }

    public function destroy(Request $request, BankStatementClassificationRule $classificationRule): RedirectResponse
    {
        $wallet = $this->resolveActiveWallet($request);
        abort_unless((int) $classificationRule->wallet_id === (int) $wallet->id, 404);
        $classificationRule->delete();
        return back()->with('success', 'Regra de classificação excluída.');
    }

    private function validated(Request $request, OfxOperationTypePolicy $policy): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'], 'match_text' => ['required', 'string', 'max:255'],
            'match_mode' => ['required', Rule::in(['contains', 'starts_with', 'exact'])], 'direction' => ['required', Rule::in(['in', 'out', 'any'])],
            'operation_type' => ['required', Rule::in($policy->codes())], 'chart_of_account_id' => ['nullable', 'integer'],
            'bank_account_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'], 'customer_id' => ['nullable', 'integer'],
            'investment_account_id' => ['nullable', 'integer'], 'active' => ['required', 'boolean'], 'priority' => ['required', 'integer', 'between:-100000,100000'],
        ]);
    }
}
