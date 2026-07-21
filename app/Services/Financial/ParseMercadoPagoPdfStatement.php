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
        if (preg_match('/\bDETALHE\s+DOS\s+MOVIMENTOS\b/ui', $text, $tableStart, PREG_OFFSET_CAPTURE)) {
            $text = mb_strcut($text, $tableStart[0][1] + strlen($tableStart[0][0]), null, 'UTF-8');
        }
        $datePattern = '\d{2}[\/-]\d{2}[\/-]\d{2,4}';
        $text = preg_replace(
            '/^(?<description>(?:Pix|Dinheiro|Transfer[eê]ncia|Pagamento|Boleto|Tarifa|Compra|Saque)[^\n]*)\n(?<date>'.$datePattern.')/umi',
            '${date} ${description}',
            $text,
        );
        $blocks = preg_split('/(?=\b\d{2}[\/-]\d{2}[\/-]\d{2,4}\b)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $transactions = [];

        foreach ($blocks as $index => $block) {
            if (! preg_match('/\b(?<date>\d{2}[\/-]\d{2}[\/-]\d{2,4})\b/u', $block, $dateMatch)) {
                continue;
            }

            preg_match_all('/(?<![\d.,])(?<value>(?:R\$\s*)?-?\s*\d{1,3}(?:\.\d{3})*,\d{2})(?![\d.,])/u', $block, $values, PREG_OFFSET_CAPTURE);
            if (($values['value'] ?? []) === []) {
                continue;
            }

            $firstValueOffset = $values['value'][0][1];
            $beforeValues = mb_strcut($block, 0, $firstValueOffset, 'UTF-8');
            preg_match_all('/\b\d{7,}\b/u', $beforeValues, $ids);
            $operationId = ($ids[0] ?? []) === [] ? null : end($ids[0]);

            $description = preg_replace([
                '/\b\d{2}[\/-]\d{2}[\/-]\d{2,4}\b/u',
                '/\b\d{7,}\b/u',
                '/(?:R\$\s*)?-?\s*\d{1,3}(?:\.\d{3})*,\d{2}/u',
                '/\b(?:saldo|valor|entradas?|sa[ií]das?|refer[eê]ncia|id\s+da\s+opera[cç][aã]o)\b\s*:?/ui',
            ], ' ', $block);
            $description = trim(preg_replace('/\s+/', ' ', $description), " -|\t");
            if ($description === '') {
                continue;
            }

            $amount = $this->amount($values['value'][0][0]);
            if ($amount > 0 && preg_match('/\b(pix\s+enviado|transfer[eê]ncia\s+enviada|pagamento|boleto|dinheiro\s+reservado|tarifa|compra|saque|sa[ií]da)\b/ui', $description)) {
                $amount *= -1;
            } elseif ($amount > 0 && preg_match('/\b(pix\s+recebido|transfer[eê]ncia\s+recebida|dinheiro\s+retirado|entrada|cr[eé]dito|venda|estorno|rendimento)\b/ui', $description)) {
                $amount = abs($amount);
            }
            if ($amount === 0) {
                continue;
            }

            $date = $this->date($dateMatch['date']);
            $identity = $operationId ?: $date.'|'.$amount.'|'.mb_strtolower($description).'|'.$index;
            $transactions[] = new ParsedOfxTransactionDTO(
                fitId: 'PDF-MP-'.hash('sha256', $identity),
                postedAt: $date,
                amountCents: abs($amount),
                direction: $amount < 0 ? 'out' : 'in',
                description: $description,
                raw: ['text_block' => $index + 1, 'operation_id' => $operationId],
            );
        }

        return $transactions;
    }

    public function normalize(string $text): string
    {
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/(?<=\d)\s*([\/-])\s*(?=\d)/u', '$1', $text);
        $text = preg_replace('/R\s*\$\s*/ui', 'R$ ', $text);
        $text = preg_replace('/(?<=R\$)\s*-\s*/u', ' -', $text);
        $text = preg_replace('/(?<=\d)\s*([,.])\s*(?=\d{2}\b)/u', '$1', $text);
        $text = preg_replace('/(?<=\d)\s+(?=\d{3}(?:\D|$))/u', '.', $text);
        $text = str_ireplace(
            ['Descrigao', 'Descrigaéo', 'operagao', 'operagaéo', 'Saidas', 'Agéncia'],
            ['Descrição', 'Descrição', 'operação', 'operação', 'Saídas', 'Agência'],
            $text,
        );
        $text = preg_replace('/[ ]{2,}/', ' ', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    private function date(string $value): string
    {
        $separator = str_contains($value, '-') ? '-' : '/';
        $format = strlen($value) === 8 ? "d{$separator}m{$separator}y" : "d{$separator}m{$separator}Y";
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
