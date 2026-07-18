<?php

namespace App\Services\Financial;

use App\DTOs\Financial\ParsedOfxTransactionDTO;
use Carbon\CarbonImmutable;
use RuntimeException;

class ParseCsvStatement
{
    public function parse(string $contents): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252,ISO-8859-1');
        }
        $lines = preg_split('/\R/', trim($contents));
        $delimiter = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $header = array_map(fn ($value) => $this->key($value), str_getcsv(array_shift($lines), $delimiter));
        $dateIndex = $this->index($header, ['data', 'date', 'dt']);
        $descriptionIndex = $this->index($header, ['descricao', 'historico', 'description', 'memo']);
        $amountIndex = $this->index($header, ['valor', 'amount']);
        $debitIndex = $this->index($header, ['debito', 'debit']);
        $creditIndex = $this->index($header, ['credito', 'credit']);
        if ($dateIndex === null || $descriptionIndex === null || ($amountIndex === null && $debitIndex === null && $creditIndex === null)) {
            throw new RuntimeException('Não foi possível reconhecer as colunas de data, descrição e valor deste CSV.');
        }

        $transactions = [];
        $errors = [];
        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') continue;
            try {
                $row = str_getcsv($line, $delimiter);
                $date = CarbonImmutable::createFromFormat($this->dateFormat($row[$dateIndex] ?? ''), trim($row[$dateIndex]))->toDateString();
                $description = trim($row[$descriptionIndex] ?? '');
                $rawAmount = $amountIndex !== null ? ($row[$amountIndex] ?? '') : '';
                if ($amountIndex === null) {
                    $credit = $this->money($row[$creditIndex] ?? '');
                    $debit = $this->money($row[$debitIndex] ?? '');
                    $amount = $credit > 0 ? $credit : -$debit;
                } else {
                    $amount = $this->money($rawAmount);
                }
                if ($description === '' || $amount === 0) throw new RuntimeException('data, descrição ou valor inválido');
                $transactions[] = new ParsedOfxTransactionDTO(
                    fitId: 'CSV-'.hash('sha256', $date.'|'.$amount.'|'.mb_strtolower($description).'|'.($lineNumber + 2)),
                    postedAt: $date, amountCents: abs($amount), direction: $amount < 0 ? 'out' : 'in',
                    description: $description, raw: ['line' => $lineNumber + 2, 'columns' => $row],
                );
            } catch (\Throwable $exception) {
                $errors[] = ['index' => $lineNumber, 'message' => 'Linha '.($lineNumber + 2).': '.$exception->getMessage()];
            }
        }
        if ($transactions === []) throw new RuntimeException('Nenhuma transação válida foi encontrada no CSV.');
        return ['started_at' => null, 'ended_at' => null, 'account' => $this->emptyAccount(), 'transactions' => $transactions, 'errors' => $errors];
    }

    private function key(string $value): string { return preg_replace('/[^a-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower(trim($value))) ?: ''); }
    private function index(array $header, array $names): ?int { foreach ($names as $name) { $index = array_search($name, $header, true); if ($index !== false) return $index; } return null; }
    private function dateFormat(string $value): string { $value = trim($value); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? 'Y-m-d' : (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) ? 'd/m/Y' : 'd-m-Y'); }
    private function money(string $value): int { $value = preg_replace('/[^0-9,\.\-]/', '', trim($value)); if ($value === '') return 0; if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace('.', '', $value); $value = str_replace(',', '.', $value); return (int) round((float) $value * 100); }
    private function emptyAccount(): array { return array_fill_keys(['container','bank_id','branch_id','account_id','account_key','account_type','broker_id','routing_number','bank_name','organization','financial_institution_id','currency'], null); }
}
