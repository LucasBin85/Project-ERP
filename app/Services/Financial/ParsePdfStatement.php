<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ParsePdfStatement
{
    public function parse(string $contents): array
    {
        if (! str_starts_with($contents, '%PDF-')) throw new RuntimeException('O arquivo selecionado não possui uma estrutura PDF válida.');
        $text = $this->extractText($contents);
        if (trim($text) === '') throw new RuntimeException('O PDF não contém texto extraível. PDFs escaneados exigem OCR; tente OFX ou CSV.');

        $transactions = [];
        $lines = preg_split('/\R+/', $text);
        foreach ($lines as $index => $line) {
            $line = trim(preg_replace('/\s+/', ' ', str_replace([';', '|'], ' ', $line)));
            if (! preg_match('/(?<date>\d{2}[\/\-]\d{2}(?:[\/\-]\d{2,4})?|\d{4}-\d{2}-\d{2})\s+(?<description>.+?)\s+(?:R\$\s*)?(?<amount>-?[\d\.]+,\d{2})(?:\s+(?:R\$\s*)?[\d\.]+,\d{2})?$/ui', $line, $match)) continue;
            try {
                $date = $this->date($match['date']);
                $description = trim($match['description'], " -|\t");
                $amount = $this->amount($match['amount']);
                if ($amount > 0 && preg_match('/\b(enviad[ao]|pagamento|boleto|compra|tarifa|saque)\b/ui', $description)) $amount *= -1;
                if ($description === '' || $amount === 0) continue;
                $transactions[] = new ParsedOfxTransactionDTO(
                    fitId: 'PDF-'.hash('sha256', $date.'|'.$amount.'|'.mb_strtolower($description).'|'.($index + 1)),
                    postedAt: $date, amountCents: abs($amount), direction: $amount < 0 ? 'out' : 'in',
                    description: $description, raw: ['text_line' => $index + 1],
                );
            } catch (\Throwable) {}
        }
        if ($transactions === []) {
            if (app()->environment('local', 'testing')) Log::debug('PDF textual sem transações reconhecidas', ['sample' => mb_substr(preg_replace('/\s+/', ' ', $text), 0, 500)]);
            throw new RuntimeException('O texto do PDF foi extraído, mas nenhum lançamento foi reconhecido. Tente OFX ou CSV.');
        }
        return ['started_at' => null, 'ended_at' => null, 'account' => array_fill_keys(['container','bank_id','branch_id','account_id','account_key','account_type','broker_id','routing_number','bank_name','organization','financial_institution_id','currency'], null), 'transactions' => $transactions, 'errors' => []];
    }

    private function extractText(string $contents): string
    {
        preg_match_all('/stream\R(.*?)\Rendstream/s', $contents, $streams);
        $segments = ($streams[1] ?? []) === [] ? [$contents] : [];
        foreach ($streams[1] ?? [] as $stream) {
            foreach ([$stream, @gzuncompress($stream), @gzinflate($stream)] as $decoded) if (is_string($decoded) && $decoded !== '') $segments[] = $decoded;
        }
        $pieces = [];
        foreach ($segments as $segment) {
            preg_match_all('/\(((?:\\.|[^\\()])*)\)/s', $segment, $literal);
            foreach ($literal[1] ?? [] as $value) $pieces[] = stripcslashes($value);
            preg_match_all('/<([0-9A-Fa-f]{4,})>\s*Tj/', $segment, $hex);
            foreach ($hex[1] ?? [] as $value) { $decoded = @hex2bin($value); if ($decoded !== false) $pieces[] = $decoded; }
        }
        $text = implode(' ', $pieces);
        return preg_replace('/\s+(?=\d{2}\/\d{2}\/\d{2,4}(?:\s|[;|]))/', "\n", $text);
    }

    private function date(string $value): string
    {
        foreach (['d/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'Y-m-d'] as $format) { if (CarbonImmutable::hasFormat($value, $format)) return CarbonImmutable::createFromFormat($format, $value)->toDateString(); }
        throw new RuntimeException('data inválida');
    }
    private function amount(string $value): int { return (int) round((float) str_replace(',', '.', str_replace('.', '', $value)) * 100); }
}
