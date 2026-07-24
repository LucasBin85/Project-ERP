<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use Carbon\CarbonImmutable;

class ParseNubankCreditCardPdf
{
    /** @return list<ParsedOfxTransactionDTO> */
    public function parse(string $text): array
    {
        if (! preg_match('/\bNubank\b|\bNu Pagamentos\b/ui', $text)) {
            return [];
        }

        $year = preg_match('/(?:vencimento|fatura)[^\n]*\b(20\d{2})\b/ui', $text, $yearMatch)
            ? (int) $yearMatch[1]
            : (int) now()->year;
        $months = ['JAN' => 1, 'FEV' => 2, 'MAR' => 3, 'ABR' => 4, 'MAI' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'SET' => 9, 'OUT' => 10, 'NOV' => 11, 'DEZ' => 12];
        preg_match_all(
            '/(?<day>\d{2})\s+(?<month>JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+(?<description>[^\n]+)(?:\n|\s)+R\$\s*(?<amount>-?[\d.]+,\d{2})/ui',
            preg_replace('/[ \t]+/', ' ', $text),
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->reject(fn (array $match) => preg_match('/\b(limite total|pagamento total da fatura|valor total da fatura)\b/ui', $match['description']))
            ->map(function (array $match, int $index) use ($months, $year) {
                $description = trim($match['description']);
                $month = $months[mb_strtoupper($match['month'])];
                $transactionYear = $month > (int) now()->month + 6 ? $year - 1 : $year;
                $amount = (int) round((float) str_replace(',', '.', str_replace('.', '', $match['amount'])) * 100);
                $credit = $amount < 0 || preg_match('/\b(estorno|cr[eé]dito|pagamento recebido)\b/ui', $description);

                return new ParsedOfxTransactionDTO(
                    fitId: 'PDF-NU-'.hash('sha256', "{$transactionYear}|{$month}|{$match['day']}|{$description}|{$amount}|{$index}"),
                    postedAt: CarbonImmutable::create($transactionYear, $month, (int) $match['day'])->toDateString(),
                    amountCents: abs($amount),
                    direction: $credit ? 'in' : 'out',
                    description: $description,
                    raw: ['text_block' => $index + 1],
                );
            })->values()->all();
    }
}
