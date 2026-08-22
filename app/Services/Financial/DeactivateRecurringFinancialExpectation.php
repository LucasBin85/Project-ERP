<?php

namespace App\Services\Financial;

use App\Models\RecurringFinancialExpectation;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateRecurringFinancialExpectation
{
    public function execute(Wallet $wallet, RecurringFinancialExpectation $expectation, CarbonInterface $effectiveFrom): RecurringFinancialExpectation
    {
        return DB::transaction(function () use ($wallet, $expectation, $effectiveFrom) {
            $current = RecurringFinancialExpectation::query()->lockForUpdate()->findOrFail($expectation->id);
            $effective = CarbonImmutable::instance($effectiveFrom)->startOfMonth();
            if ($current->wallet_id !== $wallet->id) {
                throw ValidationException::withMessages(['expectation' => 'A recorrência não pertence à carteira ativa.']);
            }
            if ($current->successor()->exists()) {
                throw ValidationException::withMessages(['expectation' => 'Somente a versão atual pode ser encerrada.']);
            }
            if ($effective->lt(now()->startOfMonth()) || $effective->lte(CarbonImmutable::instance($current->starts_on)->startOfMonth())) {
                throw ValidationException::withMessages(['effective_from' => 'Escolha uma competência atual ou futura posterior ao início da regra.']);
            }
            if ($current->occurrences()->whereDate('period_date', '>=', $effective)->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Já existem competências resolvidas a partir da data escolhida.']);
            }
            if ($current->ends_on && CarbonImmutable::instance($current->ends_on)->lt($effective)) {
                throw ValidationException::withMessages(['effective_from' => 'A regra já termina antes da competência escolhida.']);
            }
            $current->update(['ends_on' => $effective->subMonth()->endOfMonth()]);

            return $current->refresh();
        });
    }
}
