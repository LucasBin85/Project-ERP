<?php

namespace App\Services\Financial;

use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\RecurringFinancialExpectation;
use App\Models\Supplier;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CreateRecurringFinancialExpectation
{
    public function execute(Wallet $wallet, RecurringFinancialExpectationDTO $dto): RecurringFinancialExpectation
    {
        $intervalMonths = RecurringFinancialExpectation::intervalMonthsFor($dto->frequency);
        if ($intervalMonths === null) {
            throw ValidationException::withMessages(['frequency' => 'Periodicidade recorrente inválida.']);
        }

        if (! in_array($dto->type, ['payable', 'receivable'], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de recorrência inválido.']);
        }
        if (! in_array($dto->amountMode, ['fixed', 'variable'], true)) {
            throw ValidationException::withMessages(['amount_mode' => 'Modo de valor inválido.']);
        }
        if ($dto->dueDay < 1 || $dto->dueDay > 31) {
            throw ValidationException::withMessages(['due_day' => 'O dia de vencimento deve estar entre 1 e 31.']);
        }
        if ($dto->amountMode === 'fixed' && (! $dto->expectedAmountCents || $dto->expectedAmountCents < 1)) {
            throw ValidationException::withMessages(['expected_amount_cents' => 'Uma recorrência fixa exige valor positivo.']);
        }
        if ($dto->expectedAmountCents !== null && $dto->expectedAmountCents < 1) {
            throw ValidationException::withMessages(['expected_amount_cents' => 'O valor previsto deve ser positivo.']);
        }

        $startsOn = CarbonImmutable::parse($dto->startsOn);
        $endsOn = $dto->endsOn ? CarbonImmutable::parse($dto->endsOn) : null;
        if ($endsOn?->lt($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'A data final deve ser igual ou posterior à inicial.']);
        }

        if ($dto->type === 'payable') {
            $this->validatePayable($wallet, $dto);
        } else {
            $this->validateReceivable($wallet, $dto);
        }

        return RecurringFinancialExpectation::query()->create([
            'wallet_id' => $wallet->id,
            'type' => $dto->type,
            'supplier_id' => $dto->type === 'payable' ? $dto->supplierId : null,
            'customer_id' => $dto->type === 'receivable' ? $dto->customerId : null,
            'description' => trim($dto->description),
            'frequency' => $dto->frequency,
            'interval_months' => $intervalMonths,
            'due_day' => $dto->dueDay,
            'amount_mode' => $dto->amountMode,
            'expected_amount_cents' => $dto->expectedAmountCents,
            'default_account_id' => $dto->defaultAccountId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => 'active',
            'notes' => $dto->notes,
        ]);
    }

    private function validatePayable(Wallet $wallet, RecurringFinancialExpectationDTO $dto): void
    {
        if (! $dto->supplierId || ! Supplier::query()->validForPayables($wallet->id)->find($dto->supplierId)) {
            throw ValidationException::withMessages(['supplier_id' => 'Fornecedor ativo inválido.']);
        }

        if (! $this->postingAccount($wallet, $dto->defaultAccountId, 'despesa')) {
            throw ValidationException::withMessages(['default_account_id' => 'Conta de despesa inválida para a recorrência.']);
        }
    }

    private function validateReceivable(Wallet $wallet, RecurringFinancialExpectationDTO $dto): void
    {
        if (! $dto->customerId || ! Customer::query()->validForReceivables($wallet->id)->find($dto->customerId)) {
            throw ValidationException::withMessages(['customer_id' => 'Cliente ativo inválido.']);
        }

        if (! $this->postingAccount($wallet, $dto->defaultAccountId, 'receita')) {
            throw ValidationException::withMessages(['default_account_id' => 'Conta de receita inválida para a recorrência.']);
        }
    }

    private function postingAccount(Wallet $wallet, int $accountId, string $type): ?ChartOfAccount
    {
        return ChartOfAccount::query()->where('wallet_id', $wallet->id)
            ->where('type', $type)->where('allows_posting', true)
            ->whereDoesntHave('children')->find($accountId);
    }
}
