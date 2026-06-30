<?php

namespace App\Services\Accounting;

use App\Data\Accounting\BalanceSheetData;
use App\Data\Accounting\ReportSectionData;
use App\Models\Wallet;
use App\Services\Accounting\Reports\ReportTreeBuilder;


class BalanceSheetService
{
    public function __construct(
        private readonly AccountBalanceService $accountBalanceService,
        private readonly IncomeStatementService $incomeStatementService,
        private readonly ReportTreeBuilder $treeBuilder,
    ) {}

    public function build(Wallet $wallet, ?string $referenceDate = null): BalanceSheetData
    {
        $rows = $this->accountBalanceService->getRowsByWallet(
            wallet: $wallet,
            onlyPosted: true,
            toDate: $referenceDate,
            types: ['ativo', 'passivo', 'patrimonio'],
        );

        $incomeStatement = $this->incomeStatementService->build(
            wallet: $wallet,
            startDate: null,
            endDate: $referenceDate,
        );

        $netIncome = $incomeStatement->netIncomeCents();

        $assets = $this->treeBuilder->build($rows, 'ativo', 'Ativo', 'balance_cents');
        $liabilities = $this->treeBuilder->build($rows, 'passivo', 'Passivo', 'balance_cents');
        $equity = $this->treeBuilder->build($rows, 'patrimonio', 'Patrimônio Líquido', 'balance_cents');

        if ($netIncome !== 0) {
            $equity->rows->push([
                'account_id' => 'current-period-result',
                'parent_id' => null,
                'code' => '',
                'name' => 'Resultado do Exercício',
                'type' => 'patrimonio',
                'normal_balance' => 'credit',
                'nature' => 'credora',
                'allows_posting' => false,
                'debit_cents' => 0,
                'credit_cents' => 0,
                'raw_balance_cents' => 0,
                'debit_balance_cents' => 0,
                'credit_balance_cents' => $netIncome > 0 ? $netIncome : 0,
                'balance_cents' => $netIncome,
                'level' => 0,
                'is_summary' => false,
                'is_virtual' => true,
            ]);

        }

        //$assetsTotal = $assets->totalCents;
        //$liabilitiesTotal = $liabilities->totalCents;
        //$equityTotal = $equity->totalCents;

        return new BalanceSheetData(
            assets: $assets,
            liabilities: $liabilities,
            equity: new ReportSectionData(
                key: $equity->key,
                title: $equity->title,
                totalCents: $equity->totalCents + $netIncome,
                rows: $equity->rows,
            ),
            currentPeriodResultCents: $netIncome,
        );
    }
}