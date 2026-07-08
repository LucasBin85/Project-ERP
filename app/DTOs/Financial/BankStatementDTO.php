<?php

namespace App\DTOs\Financial;

use App\Models\BankAccount;
use Illuminate\Support\Collection;

final readonly class BankStatementDTO
{
    public function __construct(
        public BankStatementFiltersDTO $filters,
        public bool $ready,
        public ?BankAccount $bankAccount,
        public int $openingBalanceCents,
        public int $totalInflowsCents,
        public int $totalOutflowsCents,
        public int $closingBalanceCents,
        public Collection $transactions,
    ) {
    }

    public function toArray(): array
    {
        return [
            'filters' => $this->filters->toArray(),
            'ready' => $this->ready,
            'bank_account' => $this->bankAccount ? [
                'id' => $this->bankAccount->id,
                'name' => $this->bankAccount->name,
                'bank_name' => $this->bankAccount->bank_name,
                'bank_code' => $this->bankAccount->bank_code,
                'agency' => $this->bankAccount->agency,
                'account_number' => $this->bankAccount->account_number,
                'account_type' => $this->bankAccount->account_type,
            ] : null,
            'summary' => [
                'opening_balance_cents' => $this->openingBalanceCents,
                'total_inflows_cents' => $this->totalInflowsCents,
                'total_outflows_cents' => $this->totalOutflowsCents,
                'closing_balance_cents' => $this->closingBalanceCents,
            ],
            'transactions' => $this->transactions->values(),
        ];
    }
}
