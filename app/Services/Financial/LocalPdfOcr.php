<?php

namespace App\Services\Financial;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class LocalPdfOcr
{
    public function enabled(): bool
    {
        return (bool) config('statements.pdf_ocr.enabled', false);
    }

    public function available(): bool
    {
        return $this->executable('pdftoppm_path') !== null && $this->executable('tesseract_path') !== null;
    }

    public function extract(string $contents): string
    {
        if (! $this->available()) {
            throw new RuntimeException('OCR local não está disponível neste ambiente. Instale as dependências ou use outro formato.');
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'statement-pdf-'.Str::uuid();
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) throw new RuntimeException('Não foi possível preparar o OCR local.');
        $pdf = $directory.DIRECTORY_SEPARATOR.'statement.pdf';
        $prefix = $directory.DIRECTORY_SEPARATOR.'page';
        file_put_contents($pdf, $contents);

        try {
            $convert = new Process([$this->executable('pdftoppm_path'), '-r', (string) config('statements.pdf_ocr.dpi', 300), '-png', $pdf, $prefix]);
            $convert->setTimeout(120)->run();
            if (! $convert->isSuccessful()) throw new RuntimeException('Não foi possível converter as páginas do PDF para OCR local.');

            $text = [];
            foreach (glob($prefix.'-*.png') ?: [] as $image) {
                $ocr = new Process([$this->executable('tesseract_path'), $image, 'stdout', '-l', (string) config('statements.pdf_ocr.language', 'por+eng')]);
                $ocr->setTimeout(120)->run();
                if (! $ocr->isSuccessful()) throw new RuntimeException('O OCR local não conseguiu ler uma ou mais páginas do PDF.');
                $text[] = $ocr->getOutput();
            }
            return trim(implode("\n", $text));
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
        }
    }

    public function tools(): array
    {
        return ['pdftoppm' => $this->executable('pdftoppm_path'), 'tesseract' => $this->executable('tesseract_path')];
    }

    private function executable(string $key): ?string
    {
        $configured = (string) config('statements.pdf_ocr.'.$key);
        if ($configured !== '' && is_file($configured)) return $configured;
        return (new ExecutableFinder)->find($configured) ?: null;
    }
}
