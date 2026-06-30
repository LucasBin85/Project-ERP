<?php

use App\Services\Accounting\Reports\ReportTreeBuilder;
use Illuminate\Support\Collection;

it('builds hierarchical report sections with subtotals', function () {
    $rows = collect([
        [
            'account_id' => 1,
            'parent_id' => null,
            'code' => '4',
            'name' => 'Receitas',
            'type' => 'receita',
            'amount_cents' => 0,
        ],
        [
            'account_id' => 2,
            'parent_id' => 1,
            'code' => '4.1',
            'name' => 'Receitas Operacionais',
            'type' => 'receita',
            'amount_cents' => 0,
        ],
        [
            'account_id' => 3,
            'parent_id' => 2,
            'code' => '4.1.1',
            'name' => 'Receita de Serviços',
            'type' => 'receita',
            'amount_cents' => 850000,
        ],
    ]);

    $section = app(ReportTreeBuilder::class)
        ->build($rows, 'receita', 'Receitas', 'amount_cents');

    expect($section->totalCents)->toBe(850000)
        ->and($section->rows)->toHaveCount(3)
        ->and($section->rows[0]['code'])->toBe('4')
        ->and($section->rows[1]['code'])->toBe('4.1')
        ->and($section->rows[2]['code'])->toBe('4.1.1');
});