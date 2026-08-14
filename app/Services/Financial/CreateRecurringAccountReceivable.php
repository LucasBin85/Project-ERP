<?php

namespace App\Services\Financial;

use App\DTOs\Financial\RecurringFinancialExpectationDTO;
use App\Models\RecurringFinancialExpectation;
use App\Models\Wallet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateRecurringAccountReceivable
{
    public function __construct(
        private readonly CreateRecurringFinancialExpectation $createExpectation,
        private readonly ConfirmRecurringFinancialExpectation $confirmExpectation,
    ) {}

    public function execute(
        Wallet $wallet,
        RecurringFinancialExpectationDTO $dto,
        CarbonInterface $period,
        int $actualAmountCents,
        ?CarbonInterface $dueDate = null,
        ?string $notes = null,
    ): RecurringFinancialExpectation {
        if ($dto->type !== 'receivable') {
            throw ValidationException::withMessages(['type' => 'A recorrência deve ser do tipo a receber.']);
        }

        return DB::transaction(function () use ($wallet, $dto, $period, $actualAmountCents, $dueDate, $notes) {
            $expectation = $this->createExpectation->execute(
                $wallet,
                $this->withFixedAmount($dto, $actualAmountCents),
            );
            $this->confirmExpectation->execute($wallet, $expectation, $period, $actualAmountCents, $dueDate, $notes);

            return $expectation->fresh(['occurrences.accountReceivable']);
        });
    }

    private function withFixedAmount(RecurringFinancialExpectationDTO $dto, int $actualAmountCents): RecurringFinancialExpectationDTO
    {
        return new RecurringFinancialExpectationDTO(
            type: $dto->type, description: $dto->description, frequency: $dto->frequency,
            dueDay: $dto->dueDay, amountMode: $dto->amountMode,
            expectedAmountCents: $dto->amountMode === 'fixed' ? $actualAmountCents : $dto->expectedAmountCents,
            defaultAccountId: $dto->defaultAccountId, startsOn: $dto->startsOn, endsOn: $dto->endsOn,
            supplierId: $dto->supplierId, customerId: $dto->customerId, notes: $dto->notes,
        );
    }
}
