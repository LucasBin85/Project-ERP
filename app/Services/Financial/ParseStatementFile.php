<?php

namespace App\Services\Financial;

use RuntimeException;

class ParseStatementFile
{
    public function __construct(private readonly ParseOfxStatement $ofx, private readonly ParseCsvStatement $csv, private readonly ParsePdfStatement $pdf) {}
    public function format(string $filename): string
    {
        $format = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($format, ['ofx', 'csv', 'pdf'], true)) throw new RuntimeException('Formato não suportado. Envie um arquivo OFX, CSV ou PDF.');
        return $format;
    }
    public function parse(string $contents, string $filename): array
    {
        return match ($this->format($filename)) { 'ofx' => $this->ofx->parse($contents), 'csv' => $this->csv->parse($contents), 'pdf' => $this->pdf->parse($contents) };
    }
}
