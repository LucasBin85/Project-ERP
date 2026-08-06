<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class PreviewCreditCardStatement
{
    public function __construct(
        private readonly ParseCreditCardStatementFile $parser,
        private readonly NormalizeBankStatementDescription $descriptions,
        private readonly ResolveCreditCardStatementTarget $targets,
        private readonly ResolveCreditCardCycleDates $cycles,
        private readonly DetectCreditCardInstallment $installments,
        private readonly ResolveCreditCardInstallmentPlan $plans,
        private readonly DetectFinancialStatementFileType $fileTypes,
    ) {}

    public function execute(Wallet $wallet, CreditCard $card, string $contents, string $filename): array
    {
        if ((int) $card->wallet_id !== (int) $wallet->id || ! $card->is_active) {
            throw ValidationException::withMessages(['statement_file' => 'O cartão deve estar ativo e pertencer à wallet atual.']);
        }
        $fileType = $this->fileTypes->execute($contents, $filename);
        if ($fileType === 'bank_statement') {
            throw ValidationException::withMessages([
                'statement_file' => 'Arquivo incompatível: extrato bancário detectado. Importe-o pelo Extrato da Conta Bancária.',
            ]);
        }
        $mainCard = $card->parentCard ?: $card;
        try {
            $parsed = $this->parser->parse($contents, $filename);
        } catch (\RuntimeException $exception) {
            if ($fileType === 'unknown' && str_contains(mb_strtolower($exception->getMessage()), 'layout')) {
                throw ValidationException::withMessages([
                    'statement_file' => 'O arquivo foi lido, mas o layout não foi reconhecido para este tipo de importação.',
                ]);
            }

            throw $exception;
        }
        $target = $this->targets->execute($mainCard, $parsed, $filename);
        if ($target) {
            $cycle = $this->cycles->forReference(
                $mainCard,
                $target['reference_year'],
                $target['reference_month'],
                $target['nominal_due_at'],
            );
            $target['nominal_due_at'] = $cycle['nominal_due_at']->toDateString();
            $target['due_at'] = $cycle['due_at']->toDateString();
        }
        $targetCard = $mainCard;
        if (! empty($parsed['last_four'])) {
            $targetCard = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where(fn ($query) => $query->whereKey($mainCard->id)->orWhere('parent_card_id', $mainCard->id))
                ->where('last_four', $parsed['last_four'])
                ->where('is_active', true)
                ->first() ?? $mainCard;
        }
        $fileHash = hash('sha256', $this->parser->format($filename).'|'.$contents);
        $seen = [];
        $rows = [];

        foreach ($parsed['transactions'] as $index => $transaction) {
            $installment = $this->installments->execute($transaction->description);
            $installmentNumber = $installment['installment_number'] ?? 1;
            $installmentsTotal = $installment['installments_total'] ?? 1;
            $normalized = $this->descriptions->execute($transaction->description);
            $hash = hash('sha256', implode('|', [
                $mainCard->id, $transaction->postedAt, $normalized, $transaction->amountCents,
            ]));
            $duplicateInFile = isset($seen[$hash]);
            $seen[$hash] = true;
            $existing = CreditCardTransaction::query()
                ->with(['creditCardInvoice:id,reference_year,reference_month', 'installmentPlanItem:id,credit_card_purchase_id,installment_number'])
                ->where('wallet_id', $wallet->id)
                ->where(function ($query) use ($mainCard, $card) {
                    $query->where('credit_card_id', $card->id)
                        ->orWhereHas('creditCard', fn ($query) => $query->where('parent_card_id', $mainCard->id));
                })
                ->where(fn ($query) => $query->where('import_hash', $hash)
                    ->when($transaction->fitId, fn ($query) => $query->orWhere(fn ($query) => $query
                        ->where('external_id', $transaction->fitId)
                        ->whereDate('purchase_date', $transaction->postedAt)
                        ->where('amount_cents', $transaction->amountCents)
                        ->where('description', $transaction->description))))
                ->first();
            $credit = $transaction->direction === 'in';
            $correctInvoice = $existing?->creditCardInvoice
                && (int) $existing->creditCardInvoice->reference_year === (int) ($target['reference_year'] ?? 0)
                && (int) $existing->creditCardInvoice->reference_month === (int) ($target['reference_month'] ?? 0);
            $completeInstallment = ! $installment
                || ((int) ($existing?->installmentPlanItem?->installment_number ?? 0) === (int) $installmentNumber);
            $alreadyImported = $correctInvoice && $completeInstallment;
            $situation = $duplicateInFile ? 'possible_duplicate' : ($alreadyImported ? 'already_imported' : ($credit ? 'credit' : 'new'));
            $matches = [];
            if ($installment && $situation === 'new' && $target) {
                $matches = $this->plans->findMatches(
                    $wallet, $mainCard, $targetCard->id, $installment['normalized_description'],
                    $installmentsTotal, $installmentNumber, $transaction->amountCents,
                    (int) $target['reference_year'], (int) $target['reference_month'],
                );
                $safeMatches = collect($matches)->where('status', 'active')
                    ->where('amount_matches', true)->where('invoice_matches', true)->values();
                $pendingMatches = collect($matches)->where('status', 'pending_confirmation')
                    ->where('amount_matches', true)->where('invoice_matches', true)->values();
                $divergentMatches = collect($matches)->where('invoice_matches', true)
                    ->where('amount_matches', false)->values();
                $situation = match (true) {
                    $safeMatches->count() === 1 => 'installment_matched',
                    $safeMatches->count() > 1 => 'installment_ambiguous',
                    $pendingMatches->count() === 1 => 'installment_plan_pending',
                    $divergentMatches->count() === 1 => 'installment_divergent',
                    count($matches) > 0 => 'installment_ambiguous',
                    default => 'installment_detected',
                };
                if (in_array($situation, ['installment_matched', 'installment_plan_pending', 'installment_divergent'], true)) {
                    $selected = match ($situation) {
                        'installment_matched' => $safeMatches,
                        'installment_plan_pending' => $pendingMatches,
                        default => $divergentMatches,
                    };
                    $matches = $selected->all();
                }
            }

            $behavior = $this->financialBehavior($situation, (bool) $installment);
            $rows[] = [
                'row_key' => hash('sha256', $fileHash.'|'.$index.'|'.$hash),
                'index' => $index,
                'date' => $transaction->postedAt,
                'description' => $transaction->description,
                'amount_cents' => $transaction->amountCents,
                'external_id' => $transaction->fitId,
                'import_hash' => $hash,
                'installment_number' => $installmentNumber,
                'installments_total' => $installmentsTotal,
                'description_base' => $installment['description_base'] ?? null,
                'normalized_description' => $installment['normalized_description'] ?? null,
                'started_before_erp' => $installment['started_before_erp'] ?? false,
                'recognized_total_cents' => $installment ? $transaction->amountCents * ($installmentsTotal - $installmentNumber + 1) : null,
                'installment_plan_matches' => $matches,
                'invoice_reference' => $target['reference'] ?? null,
                'credit_card_id' => $targetCard->id,
                'credit_card_name' => $targetCard->name,
                'situation' => $situation,
                ...$behavior,
                'default_action' => match ($situation) {
                    'new' => 'create',
                    'installment_matched' => 'link_plan',
                    'installment_plan_pending' => 'link_pending_plan',
                    'installment_divergent' => 'resolve_divergence',
                    'installment_detected', 'installment_ambiguous' => 'resolve',
                    default => 'ignore',
                },
            ];
        }

        $outsidePeriod = $this->hasDatesOutsidePeriod($rows, $parsed['period_start'] ?? null, $parsed['period_end'] ?? null);
        $warnings = array_values(array_filter([
            $parsed['warning'] ?? null,
            $outsidePeriod ? 'Existem compras com datas fora do período estimado da fatura. Elas serão importadas na fatura alvo do arquivo.' : null,
        ]));

        return [
            'token' => null,
            'file_name' => $filename,
            'format' => strtoupper($this->parser->format($filename)),
            'origin' => $this->parser->format($filename) === 'pdf' && $parsed['read_source'] === 'ocr' ? 'PDF/OCR' : strtoupper($this->parser->format($filename)),
            'file_hash' => $fileHash,
            'credit_card_id' => $mainCard->id,
            'credit_card_name' => $mainCard->name,
            'institution' => $parsed['institution'] ?? null,
            'last_four' => $parsed['last_four'] ?? null,
            'holder_name' => $parsed['holder_name'] ?? null,
            'due_date' => $parsed['due_date'] ?? null,
            'ignored_items' => $parsed['ignored_items'] ?? [],
            'warning' => $parsed['warning'] ?? null,
            'warnings' => $warnings,
            'target_invoice' => $target,
            'period_start' => $parsed['period_start'] ?? $parsed['started_at'] ?? null,
            'period_end' => $parsed['period_end'] ?? $parsed['ended_at'] ?? null,
            'rows' => $rows,
            'summary' => [
                'total_cents' => (int) collect($rows)->where('composes_invoice_total', true)->sum('amount_cents'),
                'new' => collect($rows)->where('situation', 'new')->count(),
                'already_imported' => collect($rows)->where('situation', 'already_imported')->count(),
                'possible_duplicate' => collect($rows)->where('situation', 'possible_duplicate')->count(),
                'credits' => collect($rows)->where('situation', 'credit')->count(),
                'installments_pending' => collect($rows)->whereIn('situation', ['installment_detected', 'installment_ambiguous', 'installment_plan_pending', 'installment_divergent'])->count(),
                'installments_matched' => collect($rows)->where('situation', 'installment_matched')->count(),
                'ignored' => collect($rows)->where('situation', '!=', 'new')->count(),
            ],
        ];
    }

    private function financialBehavior(string $situation, bool $isInstallment): array
    {
        $ignored = in_array($situation, ['credit', 'possible_duplicate'], true);
        $alreadyImported = $situation === 'already_imported';
        $usesPlan = $isInstallment && in_array($situation, [
            'installment_matched', 'installment_plan_pending', 'installment_divergent',
        ], true);

        return [
            'composes_invoice_total' => ! $ignored,
            'creates_financial_item' => ! $ignored && ! $alreadyImported,
            'creates_accounting_recognition' => ! $ignored && ! $alreadyImported && ! $usesPlan,
            'uses_plan_recognition' => $usesPlan,
            'ignored' => $ignored,
            'reason' => match ($situation) {
                'credit' => 'Crédito ou pagamento não compõe o total das compras da fatura.',
                'possible_duplicate' => 'Linha duplicada no mesmo arquivo.',
                'already_imported' => 'Item financeiro já vinculado à fatura alvo.',
                'installment_matched' => 'Parcela usa o reconhecimento contábil do plano existente.',
                'installment_plan_pending' => 'Parcela será vinculada ao plano pendente de confirmação.',
                'installment_divergent' => 'Parcela requer conciliação com o plano existente.',
                default => $isInstallment
                    ? 'Parcela compõe a fatura e requer resolução do plano.'
                    : 'Compra compõe a fatura e gera reconhecimento contábil.',
            },
        ];
    }

    private function hasDatesOutsidePeriod(array $rows, ?string $start, ?string $end): bool
    {
        if (! $start || ! $end) {
            return false;
        }

        return collect($rows)->where('situation', 'new')->contains(
            fn (array $row) => $row['date'] < $start || $row['date'] > $end
        );
    }
}
