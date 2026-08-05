<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardInstallmentPlan;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ResolveCreditCardInstallmentPlan
{
    public function __construct(private readonly CreateJournalEntry $journalEntries) {}

    public function findMatches(
        Wallet $wallet,
        CreditCard $mainCard,
        ?int $childCardId,
        string $normalizedDescription,
        int $total,
        int $number,
        int $amountCents,
        int $year,
        int $month,
    ): array {
        return CreditCardInstallmentPlan::query()
            ->where('wallet_id', $wallet->id)
            ->where('main_credit_card_id', $mainCard->id)
            ->where('normalized_description', $normalizedDescription)
            ->where('total_installments', $total)
            ->whereIn('status', ['active', 'ambiguous', 'pending_confirmation'])
            ->when($childCardId && $childCardId !== $mainCard->id, fn ($query) => $query->where(
                fn ($query) => $query->whereNull('child_credit_card_id')->orWhere('child_credit_card_id', $childCardId)
            ))
            ->whereHas('items', fn ($query) => $query
                ->where('installment_number', $number)
                ->whereIn('status', ['expected', 'adjusted']))
            ->with(['items' => fn ($query) => $query->where('installment_number', $number)])
            ->get()
            ->map(function (CreditCardInstallmentPlan $plan) use ($amountCents, $year, $month) {
                $item = $plan->items->first();

                return [
                    'id' => $plan->id,
                    'description_base' => $plan->description_base,
                    'status' => $plan->status,
                    'expected_amount_cents' => $item?->amount_cents,
                    'imported_amount_cents' => $amountCents,
                    'amount_matches' => abs((int) $item?->amount_cents - $amountCents) <= 1,
                    'invoice_matches' => (int) $item?->expected_invoice_year === $year
                        && (int) $item?->expected_invoice_month === $month,
                ];
            })->all();
    }

    public function create(
        Wallet $wallet,
        CreditCard $mainCard,
        CreditCard $targetCard,
        CreditCardInvoice $invoice,
        CreditCardTransaction $purchase,
        array $row,
        array $decision,
    ): CreditCardInstallmentPlan {
        $number = (int) $row['installment_number'];
        $total = (int) $row['installments_total'];
        $recognizedFrom = (int) ($decision['recognized_from_installment'] ?? $number);
        $recognizedTo = (int) ($decision['recognized_to_installment'] ?? $total);
        $amounts = $this->amounts($number, $total, (int) $row['amount_cents'], $decision['installments'] ?? []);
        $recognizedTotal = (int) ($decision['recognized_total_cents'] ?? collect($amounts)
            ->filter(fn ($amount, $installment) => $installment >= $recognizedFrom && $installment <= $recognizedTo)->sum());
        $calculated = (int) collect($amounts)
            ->filter(fn ($amount, $installment) => $installment >= $recognizedFrom && $installment <= $recognizedTo)->sum();

        if ($recognizedFrom < $number || $recognizedTo > $total || $recognizedFrom > $recognizedTo || $recognizedTotal !== $calculated) {
            throw ValidationException::withMessages([
                'installments' => 'A soma das parcelas reconhecidas deve fechar exatamente com o valor a reconhecer.',
            ]);
        }

        $accountId = isset($decision['classification_account_id']) ? (int) $decision['classification_account_id'] : null;
        $pending = ($decision['action'] ?? null) === 'pending_plan';
        if (! $pending && ! $accountId) {
            throw ValidationException::withMessages([
                'classification_account_id' => 'Selecione uma classificação válida para confirmar o parcelamento.',
            ]);
        }
        if ($accountId && (! $wallet->chartOfAccounts()->whereKey($accountId)->whereIn('type', ['despesa', 'ativo'])
            ->where('allows_posting', true)->whereDoesntHave('children')->exists()
            || $accountId === (int) $mainCard->liability_account_id)) {
            throw ValidationException::withMessages(['classification_account_id' => 'A classificação do parcelamento é inválida.']);
        }
        $recognitionDate = $decision['recognition_date'] ?? $purchase->purchase_date->toDateString();
        $entry = $accountId ? $this->journalEntries->execute([
            'wallet_id' => $wallet->id,
            'entry_date' => $recognitionDate,
            'description' => 'Reconhecimento de parcelamento no cartão: '.$row['description_base'],
            'lines' => [
                ['chart_of_account_id' => $accountId, 'type' => 'debit', 'amount_cents' => $recognizedTotal],
                ['chart_of_account_id' => $mainCard->liability_account_id, 'type' => 'credit', 'amount_cents' => $recognizedTotal],
            ],
        ]) : null;

        $plan = CreditCardInstallmentPlan::query()->create([
            'wallet_id' => $wallet->id,
            'main_credit_card_id' => $mainCard->id,
            'child_credit_card_id' => $targetCard->id === $mainCard->id ? null : $targetCard->id,
            'description_base' => $decision['description_base'] ?? $row['description_base'],
            'normalized_description' => $row['normalized_description'],
            'total_installments' => $total,
            'first_known_installment' => $number,
            'recognized_from_installment' => $recognizedFrom,
            'recognized_to_installment' => $recognizedTo,
            'original_total_cents' => $decision['original_total_cents'] ?? ((int) $row['amount_cents'] * $total),
            'recognized_total_cents' => $recognizedTotal,
            'installment_amount_cents' => $row['amount_cents'],
            'classification_account_id' => $accountId,
            'recognition_journal_entry_id' => $entry?->id,
            'recognition_date' => $recognitionDate,
            'started_before_erp' => $number > 1,
            'status' => $entry ? 'active' : 'pending_confirmation',
            'source' => $decision['source'] ?? 'statement',
            'metadata_json' => ['statement_file_hash' => $row['statement_file_hash'] ?? null],
            'notes' => $decision['notes'] ?? null,
        ]);

        $reference = CarbonImmutable::create((int) $invoice->reference_year, (int) $invoice->reference_month, 1);
        foreach ($amounts as $installment => $amount) {
            $expected = $reference->addMonths($installment - $number);
            $previous = $installment < $number;
            $current = $installment === $number;
            $plan->items()->create([
                'installment_number' => $installment,
                'amount_cents' => $amount,
                'expected_invoice_year' => $previous ? null : $expected->year,
                'expected_invoice_month' => $previous ? null : $expected->month,
                'credit_card_invoice_id' => $current ? $invoice->id : null,
                'credit_card_purchase_id' => $current ? $purchase->id : null,
                'status' => $previous ? 'previous_before_erp' : ($current ? 'matched' : 'expected'),
                'source' => $current ? ($decision['source'] ?? 'statement') : 'plan',
                'matched_at' => $current ? now() : null,
            ]);
        }
        $purchase->update(['journal_entry_id' => $entry?->id ?? $purchase->journal_entry_id, 'expense_account_id' => $accountId ?? $wallet->suspense_account_id]);

        return $plan;
    }

    public function match(
        CreditCardInstallmentPlan $plan,
        CreditCardInvoice $invoice,
        CreditCardTransaction $purchase,
        int $number,
        int $amountCents,
    ): void {
        $item = $plan->items()->where('installment_number', $number)->lockForUpdate()->firstOrFail();
        if ((int) $item->expected_invoice_year !== (int) $invoice->reference_year
            || (int) $item->expected_invoice_month !== (int) $invoice->reference_month) {
            throw ValidationException::withMessages(['installment_plan' => 'A parcela esperada pertence a outra fatura.']);
        }
        if ($item->status === 'matched') {
            if ((int) $item->credit_card_purchase_id !== (int) $purchase->id) {
                throw ValidationException::withMessages(['installment_plan' => 'Esta parcela já foi conciliada com outra compra.']);
            }

            return;
        }
        if (abs((int) $item->amount_cents - $amountCents) > 1) {
            throw ValidationException::withMessages(['installment_plan' => 'O valor importado diverge da parcela esperada. Confirme um ajuste antes de conciliar.']);
        }
        $item->update([
            'credit_card_invoice_id' => $invoice->id,
            'credit_card_purchase_id' => $purchase->id,
            'status' => 'matched',
            'source' => 'statement',
            'matched_at' => now(),
        ]);
        $purchase->update([
            'expense_account_id' => $plan->classification_account_id ?? $purchase->expense_account_id,
            'journal_entry_id' => $plan->recognition_journal_entry_id,
        ]);
        if (! $plan->items()->whereIn('status', ['expected', 'adjusted', 'possible_match', 'divergent'])->exists()) {
            $plan->update(['status' => 'completed']);
        }
    }

    public function linkForReview(
        CreditCardInstallmentPlan $plan,
        CreditCardInvoice $invoice,
        CreditCardTransaction $purchase,
        int $number,
        int $amountCents,
        string $status,
    ): void {
        $item = $plan->items()->where('installment_number', $number)->lockForUpdate()->firstOrFail();
        if ((int) $item->expected_invoice_year !== (int) $invoice->reference_year
            || (int) $item->expected_invoice_month !== (int) $invoice->reference_month) {
            throw ValidationException::withMessages(['installment_plan' => 'A parcela esperada pertence a outra fatura.']);
        }
        if ($item->credit_card_purchase_id && (int) $item->credit_card_purchase_id !== (int) $purchase->id) {
            throw ValidationException::withMessages(['installment_plan' => 'Esta parcela já está vinculada a outra compra.']);
        }

        $item->update([
            'credit_card_invoice_id' => $invoice->id,
            'credit_card_purchase_id' => $purchase->id,
            'status' => $status,
            'source' => 'statement',
            'metadata_json' => array_merge($item->metadata_json ?? [], [
                'expected_amount_cents' => (int) $item->amount_cents,
                'imported_amount_cents' => $amountCents,
            ]),
        ]);
        if ($status === 'divergent') {
            $plan->update(['status' => 'ambiguous']);
        }
    }

    private function amounts(int $current, int $total, int $default, array $custom): array
    {
        $provided = collect($custom)->keyBy('installment_number');
        $amounts = [];
        for ($number = 1; $number <= $total; $number++) {
            $amounts[$number] = (int) ($provided->get($number)['amount_cents'] ?? $default);
        }

        return $amounts;
    }
}
