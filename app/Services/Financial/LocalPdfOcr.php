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
        return $this->extractWithDiagnostics($contents)['text'];
    }

    /** @return array{text: string, images: array<int, string>, commands: array<int, string>, errors: array<int, string>, languages: array<int, string>} */
    public function extractWithDiagnostics(string $contents): array
    {
        if (! $this->available()) {
            throw new RuntimeException('OCR local não está disponível neste ambiente. Instale Poppler e Tesseract ou use outro formato.');
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'statement-pdf-'.Str::uuid();
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o OCR local.');
        }

        $pdf = $directory.DIRECTORY_SEPARATOR.'statement.pdf';
        $prefix = $directory.DIRECTORY_SEPARATOR.'page';
        file_put_contents($pdf, $contents);
        $result = ['text' => '', 'images' => [], 'commands' => [], 'errors' => [], 'languages' => []];

        try {
            $convertCommand = [$this->executable('pdftoppm_path'), '-r', (string) config('statements.pdf_ocr.dpi', 300), '-png', $pdf, $prefix];
            $convert = $this->process($convertCommand);
            $convert->setTimeout(120)->run();
            $result['commands'][] = $convert->getCommandLine();
            if (! $convert->isSuccessful()) {
                throw new RuntimeException('Não foi possível converter as páginas do PDF para OCR local: '.trim($convert->getErrorOutput()));
            }

            $images = $this->generatedImages($prefix);
            sort($images, SORT_NATURAL);
            $result['images'] = array_map('basename', $images);
            if ($images === []) {
                throw new RuntimeException('O pdftoppm não gerou imagens para o OCR local.');
            }

            foreach ($images as $image) {
                $page = $this->readImage($image, (string) config('statements.pdf_ocr.language', 'eng'));
                $result['commands'] = [...$result['commands'], ...$page['commands']];
                $result['errors'] = [...$result['errors'], ...$page['errors']];
                $result['languages'] = [...$result['languages'], ...$page['languages']];
                if ($page['text'] === '') {
                    throw new RuntimeException($page['message'] ?: 'O OCR local não conseguiu ler uma ou mais páginas do PDF.');
                }
                $result['text'] .= ($result['text'] === '' ? '' : "\n").$page['text'];
            }

            $result['text'] = trim($result['text']);
            $result['languages'] = array_values(array_unique($result['languages']));
            return $result;
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function tools(): array
    {
        return ['pdftoppm' => $this->executable('pdftoppm_path'), 'tesseract' => $this->executable('tesseract_path')];
    }

    protected function process(array $command): Process
    {
        return new Process($command);
    }

    /** @return array<int, string> */
    protected function generatedImages(string $prefix): array
    {
        return glob($prefix.'-*.png') ?: [];
    }

    /** @return array{text: string, commands: array<int, string>, errors: array<int, string>, languages: array<int, string>, message: string|null} */
    private function readImage(string $image, string $configuredLanguage): array
    {
        $languages = [$configuredLanguage !== '' ? $configuredLanguage : 'eng'];
        if (str_contains($languages[0], 'por') && str_contains($languages[0], 'eng')) {
            $languages[] = 'eng';
        }

        $commands = [];
        $errors = [];
        $missing = [];
        foreach (array_unique($languages) as $language) {
            $ocr = $this->process([$this->executable('tesseract_path'), $image, 'stdout', '-l', $language]);
            $ocr->setTimeout(120)->run();
            $commands[] = $ocr->getCommandLine();
            $stdout = trim($ocr->getOutput());
            $stderr = trim($ocr->getErrorOutput());
            if ($stderr !== '') {
                $errors[] = $stderr;
            }
            if ($stdout !== '') {
                return ['text' => $stdout, 'commands' => $commands, 'errors' => $errors, 'languages' => [$language], 'message' => null];
            }
            preg_match_all("/Failed loading language ['\"]([^'\"]+)['\"]/i", $stderr, $matches);
            $missing = [...$missing, ...($matches[1] ?? [])];
            if ($ocr->isSuccessful()) {
                break;
            }
        }

        $missing = array_values(array_unique($missing));
        $message = $missing !== []
            ? collect($missing)->map(fn (string $language) => "Idioma OCR '{$language}' não está instalado.")->implode(' ')
                .' Instale o arquivo traineddata correspondente ou configure STATEMENT_PDF_OCR_LANGUAGE=eng.'
            : 'O OCR local não conseguiu ler uma ou mais páginas do PDF.';

        return ['text' => '', 'commands' => $commands, 'errors' => $errors, 'languages' => [], 'message' => $message];
    }

    private function executable(string $key): ?string
    {
        $configured = (string) config('statements.pdf_ocr.'.$key);
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        return (new ExecutableFinder)->find($configured) ?: null;
    }
}
