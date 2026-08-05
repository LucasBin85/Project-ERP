<?php

use App\Services\Financial\DetectFinancialStatementFileType;

it('detects OFX statement contexts', function () {
    $detector = app(DetectFinancialStatementFileType::class);

    expect($detector->execute('<OFX><CREDITCARDMSGSRSV1><CCSTMTRS><CCSTMTTRN>', 'fatura.ofx'))
        ->toBe('credit_card_statement')
        ->and($detector->execute('<OFX><BANKMSGSRSV1><STMTRS><BANKACCTFROM>', 'extrato.ofx'))
        ->toBe('bank_statement');
});

it('detects confident CSV statement contexts and keeps ambiguous layouts unknown', function () {
    $detector = app(DetectFinancialStatementFileType::class);

    expect($detector->execute("date,title,amount\n2026-07-01,Compra,10.00", 'fatura.csv'))
        ->toBe('credit_card_statement')
        ->and($detector->execute("data,descricao,valor,saldo\n01/07/2026,PIX,10.00,100.00", 'extrato.csv'))
        ->toBe('bank_statement')
        ->and($detector->execute("data,descricao,valor\n01/07/2026,Item,10.00", 'arquivo.csv'))
        ->toBe('unknown');
});

it('detects textual PDF contexts from financial markers', function () {
    $detector = app(DetectFinancialStatementFileType::class);
    $card = "%PDF-1.4\n(Fatura cartao limite vencimento compras) Tj\n%%EOF";
    $bank = "%PDF-1.4\n(Extrato de conta saldo inicial saldo final agencia conta entradas saidas) Tj\n%%EOF";

    expect($detector->execute($card, 'fatura.pdf'))->toBe('credit_card_statement')
        ->and($detector->execute($bank, 'extrato.pdf'))->toBe('bank_statement');
});
