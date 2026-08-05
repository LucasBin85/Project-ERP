<?php

namespace App\Services\Financial;

use Illuminate\Support\Str;

class DetectFinancialStatementFileType
{
    public function __construct(private readonly ParsePdfStatement $pdf) {}

    public function execute(string $contents, string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'ofx' => $this->ofx($contents),
            'csv' => $this->csv($contents),
            'pdf' => $this->pdf($contents),
            default => 'unknown',
        };
    }

    private function ofx(string $contents): string
    {
        $value = strtoupper($contents);
        if (str_contains($value, 'CREDITCARDMSGSRSV1') || str_contains($value, 'CCSTMTRS')
            || str_contains($value, 'CCSTMTTRN')) {
            return 'credit_card_statement';
        }
        if (str_contains($value, 'BANKMSGSRSV1') || str_contains($value, '<STMTRS>')
            || str_contains($value, '<BANKACCTFROM>')) {
            return 'bank_statement';
        }

        return 'unknown';
    }

    private function csv(string $contents): string
    {
        $header = Str::ascii(Str::lower(trim((string) preg_split('/\R/', $contents)[0])));
        $columns = preg_split('/\s*[,;]\s*/', str_replace('"', '', $header));
        $columns = array_map(fn (string $column) => trim($column), $columns ?: []);

        if (array_intersect($columns, ['title', 'titulo', 'merchant', 'estabelecimento'])
            && array_intersect($columns, ['date', 'data']) && array_intersect($columns, ['amount', 'valor'])) {
            return 'credit_card_statement';
        }
        if (array_intersect($columns, ['saldo', 'balance', 'tipo', 'type', 'identificador', 'id transacao', 'fitid'])
            && array_intersect($columns, ['descricao', 'description', 'historico', 'memo'])) {
            return 'bank_statement';
        }

        return 'unknown';
    }

    private function pdf(string $contents): string
    {
        try {
            $text = Str::ascii(Str::lower($this->pdf->extractForMetadata($contents)['text']));
        } catch (\Throwable) {
            return 'unknown';
        }

        $cardScore = $this->score($text, ['fatura', 'cartao', 'limite', 'vencimento', 'pagamento recebido', 'compras']);
        $bankScore = $this->score($text, ['extrato de conta', 'saldo inicial', 'saldo final', 'agencia', 'conta', 'entradas', 'saidas']);

        if ($cardScore >= 3 && $cardScore > $bankScore) {
            return 'credit_card_statement';
        }
        if ($bankScore >= 3 && $bankScore > $cardScore) {
            return 'bank_statement';
        }

        return 'unknown';
    }

    private function score(string $text, array $markers): int
    {
        return collect($markers)->filter(fn (string $marker) => str_contains($text, $marker))->count();
    }
}
