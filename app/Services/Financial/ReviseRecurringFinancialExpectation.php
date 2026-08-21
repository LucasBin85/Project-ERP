<?php

namespace App\Services\Financial;

use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\RecurringFinancialExpectation;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviseRecurringFinancialExpectation
{
    public function __construct(private readonly CreateRecurringFinancialExpectation $create) {}

    public function execute(Wallet $wallet, RecurringFinancialExpectation $expectation, CarbonInterface $effectiveFrom, RecurringFinancialExpectationDTO $data): RecurringFinancialExpectation
    {
        return DB::transaction(function () use ($wallet, $expectation, $effectiveFrom, $data) {
            $current = RecurringFinancialExpectation::query()->lockForUpdate()->findOrFail($expectation->id);
            $effective = CarbonImmutable::instance($effectiveFrom)->startOfMonth();
            $this->validate($wallet, $current, $effective);

            $oldEnd = $effective->subMonth()->endOfMonth();
            if ($current->ends_on && CarbonImmutable::instance($current->ends_on)->lt($effective)) {
                throw ValidationException::withMessages(['effective_from' => 'A regra já termina antes da competência escolhida.']);
            }

            $current->update(['ends_on' => $oldEnd]);
            $anchor = $data->frequency === $current->frequency ? $current->scheduleAnchorDate() : $effective;

            return $this->create->execute($wallet, new RecurringFinancialExpectationDTO(
                type: $current->type, description: $data->description, frequency: $data->frequency,
                dueDay: $data->dueDay, amountMode: $data->amountMode, expectedAmountCents: $data->expectedAmountCents,
                defaultAccountId: $data->defaultAccountId, startsOn: $effective->toDateString(), endsOn: $data->endsOn,
                supplierId: $current->type === 'payable' ? $data->supplierId : null,
                customerId: $current->type === 'receivable' ? $data->customerId : null, notes: $data->notes,
                replacesExpectationId: $current->id, scheduleAnchorDate: $anchor->toDateString(),
                forecastStrategy: $data->forecastStrategy,
            ));
        });
    }

    private function validate(Wallet $wallet, RecurringFinancialExpectation $expectation, CarbonImmutable $effective): void
    {
        if ($expectation->wallet_id !== $wallet->id) {
            throw ValidationException::withMessages(['expectation' => 'A recorrência não pertence à carteira ativa.']);
        }
        if ($expectation->successor()->exists()) {
            throw ValidationException::withMessages(['expectation' => 'Somente a versão atual pode ser revisada.']);
        }
        if ($effective->lt(now()->startOfMonth())) {
            throw ValidationException::withMessages(['effective_from' => 'Não é permitido revisar uma recorrência retroativamente.']);
        }
        if ($effective->lte(CarbonImmutable::instance($expectation->starts_on)->startOfMonth())) {
            throw ValidationException::withMessages(['effective_from' => 'A nova versão deve iniciar depois da versão atual.']);
        }
        if ($expectation->occurrences()->whereDate('period_date', '>=', $effective)->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'Já existem competências resolvidas a partir da data escolhida.']);
        }
    }
}
