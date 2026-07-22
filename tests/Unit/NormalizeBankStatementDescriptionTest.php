<?php

use App\Services\Financial\NormalizeBankStatementDescription;

it('normalizes bank descriptions while preserving useful names', function (string $raw, string $expected) {
    expect(app(NormalizeBankStatementDescription::class)->execute($raw))->toBe($expected);
})->with([
    ['Pix   enviado Lucas Bin da Silva 159729716969 R$ -996,00', 'pix enviado lucas bin da silva'],
    ['Compra FII MXRF11 123456789', 'compra fii mxrf11'],
    ['CEEE 10/07/2026 R$ 120,45', 'ceee'],
    ['Mercado Pago ---- TESOURO', 'mercado pago tesouro'],
]);
