<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use RuntimeException;

class ParseCreditCardStatementFile
{
    public function __construct(
        private readonly ParseOfxStatement $ofx,
        private readonly ParseCsvStatement $csv,
        private readonly ParsePdfStatement $pdf,
        private readonly ParseNubankCreditCardPdf $nubankPdf,
    ) {}

    public function format(string $filename): string
    {
        $format = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($format, ['ofx', 'csv', 'pdf'], true)) {
            throw new RuntimeException('Formato não suportado. Envie um arquivo OFX, CSV ou PDF.');
        }

        return $format;
    }

    public function parse(string $contents, string $filename): array
    {
        $format = $this->format($filename);
        if ($format === 'ofx') {
            $parsed = $this->ofx->parse($contents);
            $parsed['institution'] = $parsed['account']['organization'] ?? $parsed['account']['bank_name'] ?? null;
            $parsed['last_four'] = $this->lastFour($parsed['account']['account_id'] ?? null);
            $parsed['read_source'] = 'ofx';

            return $this->normalizeDirections($parsed, true);
        }
        if ($format === 'csv') {
            $parsed = $this->csv->parse($contents);
            $parsed['institution'] = null;
            $parsed['last_four'] = null;
            $parsed['read_source'] = 'csv';

            return $this->normalizeDirections($parsed, false);
        }

        $extraction = $this->pdf->extractForMetadata($contents);
        $transactions = $this->nubankPdf->parse($extraction['text']);
        if ($transactions === []) {
            throw new RuntimeException('O arquivo foi lido, mas o layout da fatura ainda não foi reconhecido.');
        }

        return [
            'started_at' => null,
            'ended_at' => null,
            'account' => [],
            'transactions' => $transactions,
            'errors' => [],
            'institution' => 'Nubank',
            'last_four' => $this->metadata($extraction['text'], '/(?:final|cart[aã]o)\D{0,20}(\d{4})/ui'),
            'holder_name' => null,
            'due_date' => $this->dueDate($extraction['text']),
            'read_source' => $extraction['source'],
        ];
    }

    private function normalizeDirections(array $parsed, bool $negativeMeansPurchase): array
    {
        $parsed['transactions'] = collect($parsed['transactions'])->map(function ($transaction) use ($negativeMeansPurchase) {
            $isCredit = preg_match('/\b(estorno|cr[eé]dito|pagamento recebido)\b/ui', $transaction->description);
            $direction = $isCredit
                ? 'in'
                : ($negativeMeansPurchase
                    ? ($transaction->direction === 'out' ? 'out' : 'in')
                    : ($transaction->direction === 'out' ? 'in' : 'out'));

            return new ParsedOfxTransactionDTO(
                fitId: $transaction->fitId,
                postedAt: $transaction->postedAt,
                amountCents: $transaction->amountCents,
                direction: $direction,
                description: $transaction->description,
                raw: $transaction->raw,
            );
        })->all();

        return $parsed;
    }

    private function lastFour(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }

    private function metadata(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $match) ? trim($match[1]) : null;
    }

    private function dueDate(string $text): ?string
    {
        if (! preg_match('/Data de vencimento:\s*(\d{2})\s+([A-Z]{3})\s+(20\d{2})/ui', $text, $match)) {
            return null;
        }
        $months = ['JAN' => 1, 'FEV' => 2, 'MAR' => 3, 'ABR' => 4, 'MAI' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'SET' => 9, 'OUT' => 10, 'NOV' => 11, 'DEZ' => 12];
        $month = $months[mb_strtoupper($match[2])] ?? null;

        return $month ? sprintf('%04d-%02d-%02d', $match[3], $month, $match[1]) : null;
    }
}
