<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use Carbon\CarbonImmutable;

class ParseNubankCreditCardPdf
{
    /** @return list<ParsedOfxTransactionDTO> */
    public function parse(string $text): array
    {
        return $this->parseWithDiagnostics($text)['transactions'];
    }

    /** @return array{transactions: list<ParsedOfxTransactionDTO>, candidates: int, ignored: list<array{reason: string, sample: string}>} */
    public function parseWithDiagnostics(string $text): array
    {
        if (! preg_match('/\bNubank\b|\bNu Pagamentos\b/ui', $text)) {
            return ['transactions' => [], 'candidates' => 0, 'ignored' => [['reason' => 'Instituição Nubank não identificada.', 'sample' => '']]];
        }

        $year = preg_match('/(?:vencimento|fatura)[^\n]*\b(20\d{2})\b/ui', $text, $yearMatch)
            ? (int) $yearMatch[1]
            : (int) now()->year;
        $months = ['JAN' => 1, 'FEV' => 2, 'MAR' => 3, 'ABR' => 4, 'MAI' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'SET' => 9, 'OUT' => 10, 'NOV' => 11, 'DEZ' => 12];
        $normalized = preg_replace('/[ \t]+/', ' ', str_replace(["\r\n", "\r"], "\n", $text));
        $section = $this->transactionSection($normalized);
        if ($section === null) {
            return ['transactions' => [], 'candidates' => 0, 'ignored' => [['reason' => 'Seção de transações não encontrada.', 'sample' => '']]];
        }

        preg_match_all(
            '/^(?<day>\d{2})\s+(?<month>JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+(?<block>.*?)(?=^\d{2}\s+(?:JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+|\z)/imsu',
            $section,
            $matches,
            PREG_SET_ORDER,
        );

        $transactions = [];
        $ignored = [];
        foreach ($matches as $index => $match) {
            $firstLine = trim((string) preg_split('/\R/u', $match['block'])[0]);
            preg_match_all('/(?<sign>[-−]?)\s*R\$\s*(?<amount>[\d.]+,\d{2})/u', $match['block'], $amounts, PREG_SET_ORDER);
            if ($amounts === []) {
                $ignored[] = ['reason' => 'Lançamento sem valor final reconhecível.', 'sample' => $this->sanitize($match['block'])];

                continue;
            }

            preg_match('/(?<sign>[-−]?)\s*R\$\s*(?<amount>[\d.]+,\d{2})/u', $firstLine, $firstLineAmount);
            $amountMatch = $firstLineAmount !== [] ? $firstLineAmount : $amounts[array_key_last($amounts)];
            $description = $firstLine;
            $description = preg_replace('/^•{2,}\s*\d{4}\s*/u', '', $description);
            $description = trim(preg_replace('/\s*[-−]?\s*R\$\s*[\d.]+,\d{2}\s*$/u', '', $description));
            if ($description === '' || preg_match('/^(?:a\s+\d{2}\s+[A-Z]{3}|total|resumo|limite)\b/ui', $description)) {
                $ignored[] = ['reason' => 'Linha de resumo ou cabeçalho.', 'sample' => $this->sanitize($match['block'])];

                continue;
            }

            $month = $months[mb_strtoupper($match['month'])];
            $transactionYear = $month > (int) now()->month + 6 ? $year - 1 : $year;
            $amount = (int) round((float) str_replace(',', '.', str_replace('.', '', $amountMatch['amount'])) * 100);
            $credit = $amountMatch['sign'] !== '' || preg_match('/\b(estorno|crédito|pagamento(?:\s+recebido|\s+em)?)\b/ui', $description);

            $transactions[] = new ParsedOfxTransactionDTO(
                fitId: 'PDF-NU-'.hash('sha256', "{$transactionYear}|{$month}|{$match['day']}|{$description}|{$amount}|{$index}"),
                postedAt: CarbonImmutable::create($transactionYear, $month, (int) $match['day'])->toDateString(),
                amountCents: $amount,
                direction: $credit ? 'in' : 'out',
                description: $description,
                raw: ['text_block' => $index + 1],
            );
        }

        return ['transactions' => $transactions, 'candidates' => count($matches), 'ignored' => $ignored];
    }

    private function transactionSection(string $text): ?string
    {
        if (! preg_match('/^TRANSAÇÕES\s+DE\s+\d{2}\s+[A-Z]{3}\s+A\s+\d{2}\s+[A-Z]{3}\s*$/imu', $text, $start, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset = $start[0][1] + strlen($start[0][0]);
        $section = substr($text, $offset);
        $section = preg_split('/^(?:Em cumprimento à regulação|Nu Pagamentos S\.A\.|Encargos e Custo Efetivo Total)/imu', $section, 2)[0];

        return trim($section);
    }

    private function sanitize(string $value): string
    {
        $value = preg_replace('/\b\d{4,}\b/u', '[número]', trim($value));

        return mb_substr(preg_replace('/\s+/u', ' ', $value), 0, 120);
    }
}
