<?php

namespace App\Services\Financial;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Smalot\PdfParser\Parser;

class ParsePdfStatement
{
    public function __construct(private readonly ParseMercadoPagoPdfStatement $mercadoPago) {}

    public function parse(string $contents): array
    {
        if (! str_starts_with($contents, '%PDF-')) throw new RuntimeException('O arquivo selecionado não possui uma estrutura PDF válida.');
        $text = $this->extractText($contents);
        if ($text === '') throw new RuntimeException('O PDF não contém texto extraível. PDFs escaneados exigem OCR; tente OFX ou CSV.');
        $transactions = $this->mercadoPago->parse($text);
        if ($transactions === []) {
            $message = 'O PDF foi lido, mas o layout ainda não foi reconhecido. Verifique se é um extrato textual do Mercado Pago ou tente outro período.';
            if (app()->environment('local', 'testing')) {
                $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', $text)))), 0, 20);
                $sample = mb_substr(preg_replace('/\b\d{3}[.\-]?\d{3}[.\-]?\d{3}[-.]?\d{2}\b|\b\d{11,14}\b/u', '[documento]', implode(' | ', $lines)), 0, 2000);
                Log::debug('PDF textual sem transações reconhecidas', ['characters' => mb_strlen($text), 'sanitized_lines' => $sample, 'hint' => 'Use esta amostra sanitizada para criar uma fixture do parser.']);
                $message .= ' Diagnóstico local: '.mb_strlen($text).' caracteres extraídos; consulte o log para as primeiras linhas sanitizadas.';
            }
            throw new RuntimeException($message);
        }
        return ['started_at' => null, 'ended_at' => null, 'account' => array_fill_keys(['container','bank_id','branch_id','account_id','account_key','account_type','broker_id','routing_number','bank_name','organization','financial_institution_id','currency'], null), 'transactions' => $transactions, 'errors' => []];
    }

    public function extractText(string $contents): string
    {
        try {
            $text = (new Parser)->parseContent($contents)->getText();
            if ($this->isUsefulText($text)) return $this->mercadoPago->normalize($text);
        } catch (\Throwable) {}
        preg_match_all('/\(((?:\\.|[^\\()])*)\)\s*Tj/s', $contents, $matches);
        $text = implode("\n", array_map(fn ($value) => stripcslashes($value), $matches[1] ?? []));
        return $this->isUsefulText($text) ? $this->mercadoPago->normalize($text) : '';
    }

    private function isUsefulText(?string $text): bool
    {
        if (! is_string($text) || trim($text) === '' || ! mb_check_encoding($text, 'UTF-8')) return false;
        $printable = preg_replace('/[\p{L}\p{N}\p{P}\p{S}\s]/u', '', $text);
        return mb_strlen($printable) <= max(5, (int) (mb_strlen($text) * 0.05));
    }
}
