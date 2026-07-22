<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankStatementClassificationRule;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class ValidateBankStatementClassificationRule
{
    public function __construct(private readonly OfxOperationTypePolicy $policy) {}

    public function validate(Wallet $wallet, array $data): array
    {
        $operation = $data['operation_type'];
        $direction = $data['direction'];
        if ($direction !== 'any' && ! $this->policy->isOperationTypeAllowedForDirection($operation, $direction)) {
            $this->fail('direction', 'A direção é incompatível com o tipo de operação.');
        }

        $targets = array_filter([
            'chart_of_account_id' => $data['chart_of_account_id'] ?? null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'investment_account_id' => $data['investment_account_id'] ?? null,
        ], fn ($value) => filled($value));
        $expected = match ($operation) {
            OfxOperationTypePolicy::TRANSFER => 'bank_account_id',
            OfxOperationTypePolicy::PAYMENT => 'supplier_id',
            OfxOperationTypePolicy::INCOME => isset($targets['customer_id']) ? 'customer_id' : 'chart_of_account_id',
            OfxOperationTypePolicy::INVESTMENT => 'investment_account_id',
            default => 'chart_of_account_id',
        };
        if (count($targets) !== 1 || ! isset($targets[$expected])) {
            $this->fail($expected, 'Informe somente a classificação compatível com o tipo da regra.');
        }

        if ($expected === 'bank_account_id') {
            $target = BankAccount::query()->where('wallet_id', $wallet->id)->where('is_active', true)->find($targets[$expected]);
            if (! $target) $this->fail($expected, 'A conta bancária não pertence à wallet ativa ou está inativa.');
        } elseif ($expected === 'supplier_id') {
            if (! Supplier::query()->where('wallet_id', $wallet->id)->whereKey($targets[$expected])->exists()) $this->fail($expected, 'O fornecedor não pertence à wallet ativa.');
        } elseif ($expected === 'customer_id') {
            if (! Customer::query()->where('wallet_id', $wallet->id)->whereKey($targets[$expected])->exists()) $this->fail($expected, 'O cliente não pertence à wallet ativa.');
        } else {
            $account = ChartOfAccount::query()->where('wallet_id', $wallet->id)->find($targets[$expected]);
            if (! $account || ! $account->allows_posting || $account->children()->exists()) $this->fail($expected, 'A conta não pertence à wallet ativa ou não aceita lançamentos.');
            $valid = match ($operation) {
                OfxOperationTypePolicy::EXPENSE, OfxOperationTypePolicy::FEE => $account->type === 'despesa',
                OfxOperationTypePolicy::INCOME => $account->type === 'receita',
                OfxOperationTypePolicy::INVESTMENT => $account->type === 'ativo' && $account->financial_group === 'investments',
                default => true,
            };
            if (! $valid) $this->fail($expected, 'A conta é incompatível com o tipo de operação.');
        }

        return $data;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
