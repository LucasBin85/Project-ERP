<?php

namespace App\Services\Financial;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Smalot\PdfParser\Parser;

class ParsePdfStatement
{
    public function __construct(
        private readonly ParseMercadoPagoPdfStatement $mercadoPago,
        private readonly LocalPdfOcr $ocr,
    ) {}

    public function parse(string $contents): array
    {
        if (! str_starts_with($contents, '%PDF-')) throw new RuntimeException('O arquivo selecionado não possui uma estrutura PDF válida.');

        $extraction = $this->extract($contents);
        $text = $extraction['text'];
        $transactions = $text === '' ? [] : $this->mercadoPago->parse($text);
        $ocrReadText = false;
        if ($transactions === [] && $this->ocr->enabled()) {
            $text = $this->mercadoPago->normalize($this->ocr->extract($contents));
            $extraction['source'] = 'ocr';
            $ocrReadText = $text !== '';
            $transactions = $text === '' ? [] : $this->mercadoPago->parse($text);
        }
        if ($text === '') {
            throw new RuntimeException('Este PDF parece ser baseado em imagem. Para importar este tipo de PDF, habilite OCR local ou use outro formato. Use OFX/CSV se disponíveis.');
        }

        if ($transactions === []) {
            if (app()->environment('local', 'testing')) Log::debug('PDF sem transações reconhecidas', $this->safeDiagnostics($text));
            throw new RuntimeException($ocrReadText
                ? 'O OCR leu o PDF, mas o layout ainda não foi reconhecido.'
                : 'O PDF foi lido, mas o layout ainda não foi reconhecido.');
        }

        return ['started_at' => null, 'ended_at' => null, 'account' => array_fill_keys(['container','bank_id','branch_id','account_id','account_key','account_type','broker_id','routing_number','bank_name','organization','financial_institution_id','currency'], null), 'transactions' => $transactions, 'errors' => [], 'read_source' => $extraction['source']];
    }

    public function extractText(string $contents): string
    {
        return $this->extract($contents)['text'];
    }

    /** @return array{text: string, source: string} */
    public function extractForMetadata(string $contents): array
    {
        if (! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('O arquivo selecionado não possui uma estrutura PDF válida.');
        }

        $extraction = $this->extract($contents);
        $text = $extraction['text'];
        $source = $extraction['source'];
        if ($text === '' && $this->ocr->enabled()) {
            $text = $this->mercadoPago->normalize($this->ocr->extract($contents));
            $source = 'ocr';
        }
        if ($text === '') {
            throw new RuntimeException('Este PDF parece ser baseado em imagem. Para importar este tipo de PDF, habilite OCR local ou use outro formato.');
        }

        return ['text' => $text, 'source' => (string) ($source ?: 'text')];
    }

    public function extract(string $contents): array
    {
        $result = ['text' => '', 'source' => null, 'smalot_text' => '', 'fallback_text' => '', 'smalot_error' => null];
        try {
            $text = (new Parser)->parseContent($contents)->getText();
            if ($this->isUsefulText($text)) $result['smalot_text'] = $this->mercadoPago->normalize($text);
        } catch (\Throwable $exception) {
            $result['smalot_error'] = $exception->getMessage();
        }

        $result['fallback_text'] = $this->fallbackText($contents);
        if ($result['smalot_text'] !== '') {
            $result['text'] = $result['smalot_text'];
            $result['source'] = 'smalot';
        } elseif ($result['fallback_text'] !== '') {
            $result['text'] = $result['fallback_text'];
            $result['source'] = 'fallback';
        }
        return $result;
    }

    public function inspect(string $contents): array
    {
        $extraction = $this->extract($contents);
        $ocr = ['attempted' => false, 'text' => '', 'images' => [], 'commands' => [], 'errors' => [], 'languages' => [], 'exception' => null];
        $text = $extraction['text'];
        $transactions = $text === '' ? [] : $this->mercadoPago->parse($text);
        if ($this->ocr->enabled() && $transactions === []) {
            $ocr['attempted'] = true;
            try {
                $ocr = [...$ocr, ...$this->ocr->extractWithDiagnostics($contents)];
                $text = $this->mercadoPago->normalize($ocr['text']);
                $transactions = $text === '' ? [] : $this->mercadoPago->parse($text);
                if ($text !== '') {
                    $extraction['text'] = $text;
                    $extraction['source'] = 'ocr';
                }
            } catch (\Throwable $exception) {
                $ocr['exception'] = $exception->getMessage();
            }
        }
        return $extraction + [
            'bytes' => strlen($contents),
            'objects' => preg_match_all('/\d+\s+\d+\s+obj\b/', $contents),
            'streams' => preg_match_all('/\bstream\R/', $contents),
            'images' => preg_match_all('/\/Subtype\s*\/Image\b/', $contents),
            'compressed' => preg_match_all('/\/(?:FlateDecode|LZWDecode|DCTDecode|JPXDecode)\b/', $contents),
            'encrypted' => str_contains($contents, '/Encrypt'),
            'pages' => preg_match_all('/\/Type\s*\/Page\b(?!s)/', $contents),
            'transactions' => count($transactions),
            'ocr' => $ocr,
        ];
    }

    public function sanitizeSample(string $text): string
    {
        $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', $text)))), 0, 20);
        $sample = implode(' | ', $lines);
        $sample = preg_replace('/\b\d{3}[.\-]?\d{3}[.\-]?\d{3}[-.]?\d{2}\b|\b\d{2}[.\-]?\d{3}[.\-]?\d{3}[\/]?\d{4}[-.]?\d{2}\b/u', '[documento]', $sample);
        $sample = preg_replace('/\b(?:ag(?:ência)?|conta)\s*:?[\s\-]*[\d.\-]+/ui', '$1 [mascarado]', $sample);
        return mb_substr($sample, 0, 1200);
    }

    private function fallbackText(string $contents): string
    {
        $pieces = [];
        preg_match_all('/\(((?:\\.|[^\\()])*)\)\s*Tj/s', $contents, $literal);
        foreach ($literal[1] ?? [] as $value) $pieces[] = stripcslashes($value);
        preg_match_all('/<([0-9A-Fa-f]{4,})>\s*Tj/', $contents, $hex);
        foreach ($hex[1] ?? [] as $value) {
            $decoded = @hex2bin($value);
            if (is_string($decoded)) $pieces[] = str_starts_with($decoded, "\xFE\xFF") ? mb_convert_encoding(substr($decoded, 2), 'UTF-8', 'UTF-16BE') : $decoded;
        }
        $text = implode("\n", $pieces);
        return $this->isUsefulText($text) ? $this->mercadoPago->normalize($text) : '';
    }

    private function safeDiagnostics(string $text): array
    {
        return ['characters' => mb_strlen($text), 'sanitized_lines' => $this->sanitizeSample($text)];
    }

    private function isUsefulText(?string $text): bool
    {
        if (! is_string($text) || trim($text) === '' || ! mb_check_encoding($text, 'UTF-8')) return false;
        $printable = preg_replace('/[\p{L}\p{N}\p{P}\p{S}\s]/u', '', $text);
        return mb_strlen($printable) <= max(5, (int) (mb_strlen($text) * 0.05));
    }
}
