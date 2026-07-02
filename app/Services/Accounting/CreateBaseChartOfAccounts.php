<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Wallet;

class CreateBaseChartOfAccounts
{
    public function handle(Wallet $wallet): void
    {
        $baseAccounts = $this->getBaseStructure();

        $createRecursive = function (?int $parentId, array $accountData) use (&$createRecursive, $wallet) {

            $normalBalance = in_array($accountData['type'], ['ativo', 'despesa'], true)
                ? 'debit'
                : 'credit';

            $account = ChartOfAccount::create([
                'wallet_id'        => $wallet->id,
                'parent_id'        => $parentId,
                'code'             => $accountData['code'],
                'name'             => $accountData['name'],
                'type'             => $accountData['type'],
                'normal_balance'   => $normalBalance,
                'is_system'        => $accountData['is_system'] ?? true,
                'allows_posting'   => $accountData['allows_posting'] ?? false,
                'financial_group'  => $accountData['financial_group'] ?? null,
            ]);

            foreach ($accountData['children'] ?? [] as $child) {
                $createRecursive($account->id, $child);
            }
        };

        foreach ($baseAccounts as $root) {
            $createRecursive(null, $root);
        }

        $this->defineSuspenseAccount($wallet);
    }

    private function defineSuspenseAccount(Wallet $wallet): void
    {
        if ($wallet->suspense_account_id) {
            return;
        }

        $suspense = ChartOfAccount::query()
            ->where('wallet_id', $wallet->id)
            ->where('code', '1.1.99')
            ->first();

        if ($suspense) {
            $wallet->update([
                'suspense_account_id' => $suspense->id,
            ]);
        }
    }

    private function getBaseStructure(): array
    {
        return [
            [
                'code' => '1',
                'name' => 'Ativo',
                'type' => 'ativo',
                'children' => [
                    [
                        'code' => '1.1',
                        'name' => 'Disponível',
                        'type' => 'ativo',
                        'financial_group' => 'available',
                        'children' => [
                            [
                                'code' => '1.1.1',
                                'name' => 'Caixa',
                                'type' => 'ativo',
                                'allows_posting' => true,
                                'financial_group' => 'available',
                            ],
                            [
                                'code' => '1.1.2',
                                'name' => 'Bancos',
                                'type' => 'ativo',
                                'financial_group' => 'available',
                                'children' => [
                                    [
                                        'code' => '1.1.2.001',
                                        'name' => 'Conta Transitória Bancária',
                                        'type' => 'ativo',
                                        'allows_posting' => true,
                                        'financial_group' => 'available',
                                    ],
                                ],
                            ],
                            [
                                'code' => '1.1.99',
                                'name' => 'A classificar',
                                'type' => 'ativo',
                                'allows_posting' => true,
                                'financial_group' => 'available',
                            ],
                        ],
                    ],
                    [
                        'code' => '1.2',
                        'name' => 'Contas a Receber',
                        'type' => 'ativo',
                        'financial_group' => 'accounts_receivable',
                        'children' => [
                            [
                                'code' => '1.2.1',
                                'name' => 'Clientes Diversos',
                                'type' => 'ativo',
                                'allows_posting' => true,
                                'financial_group' => 'accounts_receivable',
                            ],
                        ],
                    ],
                    [
                        'code' => '1.3',
                        'name' => 'Investimentos',
                        'type' => 'ativo',
                        'financial_group' => 'investments',
                        'children' => [
                            [
                                'code' => '1.3.1',
                                'name' => 'Aplicações Financeiras',
                                'type' => 'ativo',
                                'allows_posting' => true,
                                'financial_group' => 'investments',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'code' => '2',
                'name' => 'Passivo',
                'type' => 'passivo',
                'children' => [
                    [
                        'code' => '2.1',
                        'name' => 'Contas a Pagar',
                        'type' => 'passivo',
                        'financial_group' => 'accounts_payable',
                        'children' => [
                            [
                                'code' => '2.1.1',
                                'name' => 'Fornecedores Diversos',
                                'type' => 'passivo',
                                'allows_posting' => true,
                                'financial_group' => 'accounts_payable',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'code' => '3',
                'name' => 'Patrimônio Líquido',
                'type' => 'patrimonio',
                'children' => [
                    [
                        'code' => '3.1',
                        'name' => 'Capital Social',
                        'type' => 'patrimonio',
                        'allows_posting' => true,
                    ],
                    [
                        'code' => '3.9',
                        'name' => 'Saldos Iniciais',
                        'type' => 'patrimonio',
                        'normal_balance' => 'credit',
                        'allows_posting' => true,
                        'is_system' => true,
                    ],
                ],
            ],
            [
                'code' => '4',
                'name' => 'Receitas',
                'type' => 'receita',
                'children' => [
                    [
                        'code' => '4.1',
                        'name' => 'Receitas Operacionais',
                        'type' => 'receita',
                        'children' => [
                            [
                                'code' => '4.1.1',
                                'name' => 'Receita de Serviços',
                                'type' => 'receita',
                                'allows_posting' => true,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'code' => '5',
                'name' => 'Despesas',
                'type' => 'despesa',
                'children' => [
                    [
                        'code' => '5.1',
                        'name' => 'Despesas Operacionais',
                        'type' => 'despesa',
                        'children' => [
                            [
                                'code' => '5.1.1',
                                'name' => 'Despesas Administrativas',
                                'type' => 'despesa',
                                'allows_posting' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}