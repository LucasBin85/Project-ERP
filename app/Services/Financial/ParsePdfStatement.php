<?php

namespace App\Services\Financial;

use RuntimeException;

class ParsePdfStatement
{
    public function __construct(private readonly ParseCsvStatement $csv) {}

    public function parse(string $contents): array
    {
        if (! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('O arquivo selecionado não possui uma estrutura PDF válida.');
        }
        preg_match_all('/\(([^()]*)\)\s*Tj/s', $contents, $matches);
        $text = implode("\n", array_map(fn ($value) => stripcslashes($value), $matches[1] ?? []));
        $rows = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^(\d{2}[\/\-]\d{2}[\/\-]\d{4}|\d{4}-\d{2}-\d{2})\s*[;|]\s*(.+?)\s*[;|]\s*(-?[\d\.]+,?\d{0,2})$/u', trim($line), $match)) {
                $rows[] = implode(';', [$match[1], $match[2], $match[3]]);
            }
        }
        if ($rows === []) {
            throw new RuntimeException('Não foi possível interpretar este PDF automaticamente. Tente OFX ou CSV.');
        }
        return $this->csv->parse("data;descricao;valor\n".implode("\n", $rows));
    }
}
