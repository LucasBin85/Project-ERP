<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class ParseOfxStatement
{
    public function parse(string $contents): array
    {
        $contents = $this->normalize($contents);

        preg_match('/<DTSTART>([^<\r\n]+)/i', $contents, $startMatch);
        preg_match('/<DTEND>([^<\r\n]+)/i', $contents, $endMatch);

        preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>)/is', $contents, $matches);

        $transactions = collect($matches[1] ?? [])
            ->map(fn (string $block) => $this->parseTransaction($block))
            ->filter()
            ->values()
            ->all();

        if (count($transactions) === 0) {
            throw new RuntimeException('Nenhuma transação encontrada no arquivo OFX.');
        }

        return [
            'started_at' => isset($startMatch[1]) ? $this->parseDate($startMatch[1]) : null,
            'ended_at' => isset($endMatch[1]) ? $this->parseDate($endMatch[1]) : null,
            'transactions' => $transactions,
        ];
    }

    private function parseTransaction(string $block): ?ParsedOfxTransactionDTO
    {
        $amount = $this->tag($block, 'TRNAMT');
        $postedAt = $this->tag($block, 'DTPOSTED') ?: $this->tag($block, 'DTUSER');

        if ($amount === null || $postedAt === null) {
            return null;
        }

        $fitId = $this->tag($block, 'FITID')
            ?: sha1($postedAt . '|' . $amount . '|' . $this->tag($block, 'MEMO') . '|' . $this->tag($block, 'NAME'));

        $amountCents = $this->moneyToCents($amount);
        $direction = $amountCents >= 0 ? 'in' : 'out';
        $absoluteAmountCents = abs($amountCents);

        $name = $this->tag($block, 'NAME');
        $memo = $this->tag($block, 'MEMO');
        $payee = $this->tag($block, 'PAYEE');
        $checkNumber = $this->tag($block, 'CHECKNUM');
        $transactionType = $this->tag($block, 'TRNTYPE');

        $description = collect([$name, $memo, $payee])
            ->filter()
            ->unique()
            ->join(' - ');

        if ($description === '') {
            $description = $transactionType ?: 'Transação OFX';
        }

        return new ParsedOfxTransactionDTO(
            fitId: trim($fitId),
            postedAt: $this->parseDate($postedAt),
            amountCents: $absoluteAmountCents,
            direction: $direction,
            description: Str::limit($this->cleanText($description), 255, ''),
            raw: [
                'trntype' => $transactionType,
                'dtposted' => $postedAt,
                'trnamt' => $amount,
                'fitid' => $fitId,
                'name' => $name,
                'memo' => $memo,
                'payee' => $payee,
                'checknum' => $checkNumber,
            ],
        );
    }

    private function tag(string $block, string $tag): ?string
    {
        if (! preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]+)/i', $block, $match)) {
            return null;
        }

        return $this->cleanText($match[1]);
    }

    private function moneyToCents(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));

        return (int) round(((float) $normalized) * 100);
    }

    private function parseDate(string $value): string
    {
        $raw = trim($value);
        $date = substr($raw, 0, 8);

        return CarbonImmutable::createFromFormat('Ymd', $date)->toDateString();
    }

    private function normalize(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        return mb_convert_encoding($contents, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    private function cleanText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
