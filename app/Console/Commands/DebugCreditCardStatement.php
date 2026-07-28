<?php

namespace App\Console\Commands;

use App\Services\Financial\ParseNubankCreditCardPdf;
use App\Services\Financial\ParsePdfStatement;
use Illuminate\Console\Command;

class DebugCreditCardStatement extends Command
{
    protected $signature = 'credit-card:debug-statement {path : Caminho do PDF local}';

    protected $description = 'Diagnostica localmente uma fatura de cartão sem persistir ou expor seu conteúdo';

    public function handle(ParsePdfStatement $pdf, ParseNubankCreditCardPdf $nubank): int
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

        $extraction = $pdf->extractForMetadata((string) file_get_contents($resolved));
        $result = $nubank->parseWithDiagnostics($extraction['text']);
        $purchases = collect($result['transactions'])->where('direction', 'out');
        $credits = collect($result['transactions'])->where('direction', 'in');

        $this->table(['Diagnóstico', 'Resultado'], [
            ['Fonte usada', $extraction['source']],
            ['Caracteres extraídos', mb_strlen($extraction['text'])],
            ['Linhas candidatas', $result['candidates']],
            ['Compras reconhecidas', $purchases->count()],
            ['Créditos/pagamentos ignorados', $credits->count()],
            ['Itens rejeitados pelo parser', count($result['ignored'])],
            ['Total reconhecido', 'R$ '.number_format($purchases->sum('amountCents') / 100, 2, ',', '.')],
        ]);

        foreach (collect($result['ignored'])->countBy('reason') as $reason => $count) {
            $this->warn("Ignorados ({$count}): {$reason}");
        }
        $this->line('Amostra sanitizada: '.$pdf->sanitizeSample($extraction['text']));

        return self::SUCCESS;
    }
}
