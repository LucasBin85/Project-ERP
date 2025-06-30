<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        //'type',
        //'currency',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chartOfAccounts()
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    protected static function booted()
    {
        static::created(function (Wallet $wallet) {
            // Ao criar a carteira, gera o plano base
            self::createBaseChartOfAccounts($wallet);
        });
    }

    /**
     * Cria o plano de contas base para a carteira recém-criada.
     */
    protected static function createBaseChartOfAccounts(Wallet $wallet)
    {
        // Exemplo de estrutura básica:
        $baseAccounts = [
            // Ativo
            [
                'code'          => '1',
                'name'          => 'Ativo',
                'type'          => 'ativo',
                'is_protected'  => true,
                'children'      => [
                    [
                        'code'         => '1.1',
                        'name'         => 'Ativo Circulante',
                        'type'         => 'ativo',
                        'is_protected' => true,
                        'children'   => [
                            [
                                'code'         => '1.1.1',
                                'name'         => 'Disponível',
                                'type'         => 'ativo',
                                'is_protected' => true,
                                'children'     => [
                                    [
                                        'code'         => '1.1.1.1',
                                        'name'         => 'Caixa',
                                        'type'         => 'ativo',
                                        'is_protected' => true,
                                    ],
                                    [
                                        'code'         => '1.1.1.2',
                                        'name'         => 'Banco Conta Movimento',
                                        'type'         => 'ativo',
                                        'is_protected' => true,
                                    ]
                                ],
                            ],
                        ],
                    ],
                    [
                            'code'         => '1.2',
                            'name'         => 'Ativo Não Circulante',
                            'type'         => 'ativo',
                            'is_protected' => true,
                            // poderíamos criar children também, se desejar...
                            'children'     => [],
                    ],
                ],
            ],
            // Passivo
            [
                'code'          => '2',
                'name'          => 'Passivo',
                'type'          => 'passivo',
                'is_protected'  => true,
                'children'      => [
                    [
                        'code'         => '2.1',
                        'name'         => 'Passivo Circulante',
                        'type'         => 'passivo',
                        'is_protected' => true,
                        'children'     => [
                            [
                                'code'         => '2.1.1',
                                'name'         => 'Fornecedores',
                                'type'         => 'passivo',
                                'is_protected' => true,
                            ],
                            [
                                'code'         => '2.1.2',
                                'name'         => 'Obrigações Fiscais',
                                'type'         => 'passivo',
                                'is_protected' => true,
                            ],
                        ],
                    ],
                    [
                        'code'         => '2.2',
                        'name'         => 'Passivo Não Circulante',
                        'type'         => 'passivo',
                        'is_protected' => true,
                        'children'     => [],
                    ],
                ],
            ],

            // Patrimônio Líquido
            [
                'code'          => '3',
                'name'          => 'Patrimônio Líquido',
                'type'          => 'patrimonio',
                'is_protected'  => true,
                'children'      => [
                    [
                        'code'         => '3.1',
                        'name'         => 'Capital Social',
                        'type'         => 'patrimonio',
                        'is_protected' => true,
                    ],
                    [
                        'code'         => '3.2',
                        'name'         => 'Reservas',
                        'type'         => 'patrimonio',
                        'is_protected' => true,
                    ],
                ],
            ],

            // Receita
            [
                'code'          => '4',
                'name'          => 'Receita',
                'type'          => 'receita',
                'is_protected'  => true,
                'children'      => [
                    [
                        'code'         => '4.1',
                        'name'         => 'Receita Operacional',
                        'type'         => 'receita',
                        'is_protected' => true,
                    ],
                ],
            ],

            // Despesa
            [
                'code'          => '5',
                'name'          => 'Despesa',
                'type'          => 'despesa',
                'is_protected'  => true,
                'children'      => [
                    [
                        'code'         => '5.1',
                        'name'         => 'Despesas Operacionais',
                        'type'         => 'despesa',
                        'is_protected' => true,
                    ],
                    [
                        'code'         => '5.2',
                        'name'         => 'Despesas Financeiras',
                        'type'         => 'despesa',
                        'is_protected' => true,
                    ],
                ],
            ],

        ];

        // Função recursiva para criar contas e subcontas
        $createRecursive = function (?int $parentId, array $accountData) use (&$createRecursive, $wallet) {
            /** @var ChartOfAccount $account */
            $account = ChartOfAccount::create([
                'wallet_id'    => $wallet->id,
                'parent_id'    => $parentId,
                'code'         => $accountData['code'],
                'name'         => $accountData['name'],
                'type'         => $accountData['type'],
                'is_protected' => $accountData['is_protected'] ?? false,
            ]);

            // Se houver filhos, cria ciclicamente
            if (! empty($accountData['children'])) {
                foreach ($accountData['children'] as $childData) {
                    $createRecursive($account->id, $childData);
                }
            }
        };

        // Para cada conta de primeiro nível, o parent_id é null
        foreach ($baseAccounts as $rootAccountData) {
            $createRecursive(null, $rootAccountData);
        }
    }
}
