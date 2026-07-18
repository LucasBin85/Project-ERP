<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use Carbon\CarbonImmutable;

class ParseMercadoPagoPdfStatement
{
    /** @return array<int, ParsedOfxTransactionDTO> */
    public function parse(string $text): array
    {
        $text = $this->normalize($text);
        $blocks = preg_split('/(?=\b\d{2}\/\d{2}\/\d{2,4}\b)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $transactions = [];
        foreach ($blocks as $index => $block) {
            if (! preg_match('/\b(?<date>\d{2}\/\d{2}\/\d{2,4})\b/u', $block, $dateMatch)) continue;
            preg_match_all('/(?<![\d.,])(?<value>-?\s*(?:R\$\s*)?\d{1,3}(?:\.\d{3})*,\d{2})(?![\d.,])/u', $block, $values);
            if (($values['value'] ?? []) === []) continue;

            $description = trim(preg_replace([
                '/\b\d{2}\/\d{2}\/\d{2,4}\b/u',
                '/-?\s*R\$\s*\d{1,3}(?:\.\d{3})*,\d{2}/u',
                '/\b(?:saldo|valor|entrada|saída|referência|id da operação)\b\s*:?/ui',
            ], ' ', $block));
            $description = trim(preg_replace('/\s+/', ' ', $description), " -|\t");
            if ($description === '') continue;

            $amount = $this->amount($values['value'][0]);
            if ($amount > 0 && preg_match('/\b(enviad[ao]|pagamento|boleto|compra|retirada|tarifa|saída|saque)\b/ui', $description)) $amount *= -1;
            if ($amount > 0 && preg_match('/\b(recebid[ao]|entrada|crédito|venda|estorno|rendimento)\b/ui', $description)) $amount = abs($amount);
            if ($amount === 0) continue;

            $date = $this->date($dateMatch['date']);
            $transactions[] = new ParsedOfxTransactionDTO(
                fitId: 'PDF-'.hash('sha256', $date.'|'.$amount.'|'.mb_strtolower($description).'|'.$index),
                postedAt: $date, amountCents: abs($amount), direction: $amount < 0 ? 'out' : 'in',
                description: $description, raw: ['text_block' => $index + 1],
            );
        }
        return $transactions;
    }

    public function normalize(string $text): string
    {
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ ]{2,}/', ' ', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    private function date(string $value): string
    {
        $format = strlen($value) === 8 ? 'd/m/y' : 'd/m/Y';
        return CarbonImmutable::createFromFormat($format, $value)->toDateString();
    }

    private function amount(string $value): int
    {
        $negative = str_contains($value, '-');
        $number = preg_replace('/[^\d,.]/', '', $value);
        $cents = (int) round((float) str_replace(',', '.', str_replace('.', '', $number)) * 100);
        return $negative ? -$cents : $cents;
    }
}
