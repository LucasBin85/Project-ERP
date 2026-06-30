<?php

namespace App\Services\Accounting;

use App\Models\Wallet;

class TrialBalanceService
{
    public function __construct(
        private readonly AccountBalanceService $accountBalanceService,
    ) {}

    public function generate(
        Wallet $wallet,
        ?string $from = null,
        ?string $to = null
    ): array {
        $rows = $this->accountBalanceService->getRowsByWallet(
            wallet: $wallet,
            onlyPosted: true,
            fromDate: $from,
            toDate: $to,
            onlyWithMovementOrBalance: true,
        );

        $totalDebit = $rows->sum('debit_cents');
        $totalCredit = $rows->sum('credit_cents');
        $totalDebitBalance = $rows->sum('debit_balance_cents');
        $totalCreditBalance = $rows->sum('credit_balance_cents');

        return [
            'rows' => $rows,
            'totals' => [
                'debit_cents' => $totalDebit,
                'credit_cents' => $totalCredit,
                'debit_balance_cents' => $totalDebitBalance,
                'credit_balance_cents' => $totalCreditBalance,
                'difference_cents' => $totalDebit - $totalCredit,
                'balance_difference_cents' => $totalDebitBalance - $totalCreditBalance,
            ],
        ];
    }
}
