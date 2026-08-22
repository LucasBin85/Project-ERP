<?php

namespace App\Services\Financial;

use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

class FindBankStatementRecurringCandidates
{
    public function __construct(
        private readonly FindBankStatementPayableCandidates $payables,
        private readonly FindBankStatementReceivableCandidates $receivables,
        private readonly ListRecurringFinancialExpectationsForRange $recurring,
    ) {}

    public function execute(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry, string $type): array
    {
        match ($type) {
            'payable' => $this->payables->execute($wallet, $bankAccount, $entry),
            'receivable' => $this->receivables->execute($wallet, $bankAccount, $entry),
            default => throw new \InvalidArgumentException('Tipo recorrente inválido.'),
        };

        $statementDate = CarbonImmutable::instance($entry->entry_date)->startOfDay();
        $amount = (int) $entry->lines()->where('chart_of_account_id', $bankAccount->chart_of_account_id)->value('amount_cents');
        $statementDescription = BankStatementImportTransaction::query()
            ->where('journal_entry_id', $entry->id)->latest('id')->value('description');

        return collect($this->recurring->execute(
            $wallet, $type, $statementDate->subDays(31), $statementDate->addDays(31),
        ))->filter(fn (array $candidate) => $candidate['amount_mode'] !== 'fixed'
            || (int) $candidate['expected_amount_cents'] === $amount)
            ->map(function (array $candidate) use ($statementDate, $amount, $statementDescription) {
                $expected = $candidate['expected_amount_cents'];
                $difference = $expected === null ? null : $amount - (int) $expected;
                $description = mb_strtolower((string) $statementDescription);
                $needles = array_filter([
                    mb_strtolower((string) $candidate['description']),
                    mb_strtolower((string) ($candidate['counterparty']['name'] ?? '')),
                ]);

                return [
                    'expectation_id' => $candidate['expectation_id'],
                    'period_date' => $candidate['period_date'],
                    'description' => $candidate['description'],
                    'counterparty' => $candidate['counterparty'],
                    'default_account' => $candidate['default_account'],
                    'frequency' => $candidate['frequency'],
                    'amount_mode' => $candidate['amount_mode'],
                    'expected_due_date' => $candidate['due_date'],
                    'expected_amount_cents' => $expected,
                    'statement_date' => $statementDate->toDateString(),
                    'statement_amount_cents' => $amount,
                    'statement_description' => $statementDescription,
                    'proximity_days' => (int) abs(CarbonImmutable::parse($candidate['due_date'])->diffInDays($statementDate, false)),
                    'amount_difference_cents' => $difference,
                    'amount_difference_percent' => $expected ? round(abs($difference) / $expected * 100, 2) : null,
                    'is_overdue_at_statement' => CarbonImmutable::parse($candidate['due_date'])->lt($statementDate),
                    'description_match' => $description !== '' && collect($needles)->contains(fn (string $needle) => $needle !== '' && str_contains($description, $needle)),
                ];
            })->sort(function (array $left, array $right) {
                return $left['proximity_days'] <=> $right['proximity_days']
                    ?: ($left['amount_difference_percent'] ?? INF) <=> ($right['amount_difference_percent'] ?? INF)
                    ?: $left['expected_due_date'] <=> $right['expected_due_date']
                    ?: $left['expectation_id'] <=> $right['expectation_id']
                    ?: $left['period_date'] <=> $right['period_date'];
            })->values()->all();
    }
}
