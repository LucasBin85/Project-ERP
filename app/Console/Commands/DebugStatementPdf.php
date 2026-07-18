<?php

namespace App\Console\Commands;

use App\Services\Financial\LocalPdfOcr;
use App\Services\Financial\ParsePdfStatement;
use Illuminate\Console\Command;

class DebugStatementPdf extends Command
{
    protected $signature = 'statement:debug-pdf {path : Caminho do PDF local}';
    protected $description = 'Diagnostica localmente a extração de um PDF de extrato sem persistir seu conteúdo';

    public function handle(ParsePdfStatement $parser, LocalPdfOcr $ocr): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Este comando só pode ser executado em ambiente local ou testing.');
            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $resolved = realpath(base_path($path)) ?: realpath($path);
        if ($resolved === false || ! is_file($resolved)) {
            $this->error('Arquivo PDF não encontrado: '.$path);
            return self::FAILURE;
        }

        $contents = file_get_contents($resolved);
        $details = $parser->inspect($contents);
        $this->table(['Diagnóstico', 'Resultado'], [
            ['Caminho', $resolved], ['Tamanho', number_format($details['bytes'], 0, ',', '.').' bytes'],
            ['Páginas estimadas', $details['pages']], ['Objetos', $details['objects']], ['Streams', $details['streams']],
            ['Imagens', $details['images']], ['Streams/objetos compactados', $details['compressed']],
            ['Protegido/criptografado', $details['encrypted'] ? 'sim' : 'não'],
            ['Texto via smalot', mb_strlen($details['smalot_text']).' caracteres'],
            ['Erro smalot', $details['smalot_error'] ?: 'nenhum'],
            ['Texto via fallback', mb_strlen($details['fallback_text']).' caracteres'],
            ['Fonte escolhida', $details['source'] ?: 'nenhuma'], ['Lançamentos reconhecidos', $details['transactions']],
            ['OCR habilitado', $ocr->enabled() ? 'sim' : 'não'],
            ['pdftoppm', $ocr->tools()['pdftoppm'] ?: 'indisponível'], ['tesseract', $ocr->tools()['tesseract'] ?: 'indisponível'],
        ]);
        if ($details['text'] !== '') $this->line('Amostra sanitizada: '.$parser->sanitizeSample($details['text']));
        if ($details['text'] === '') {
            $reason = $details['encrypted'] ? 'O PDF é protegido/criptografado e o parser textual não consegue abri-lo.' : 'Nenhuma camada textual útil foi extraída.';
            $this->warn('Motivo da falha: '.$reason);
        }
        return self::SUCCESS;
    }
}
