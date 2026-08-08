<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmCreditCardStatement
{
    public function __construct(
        private readonly ParseCreditCardStatementFile $parser,
        private readonly ResolveCreditCardInvoice $invoices,
        private readonly CreateJournalEntry $journalEntries,
        private readonly ResolveCreditCardInstallmentPlan $installmentPlans,
    ) {}

    public function execute(
        Wallet $wallet,
        CreditCard $card,
        array $preview,
        string $contents,
        string $filename,
        array $decisions,
        ?int $targetYear = null,
        ?int $targetMonth = null,
    ): array {
        if ((int) $card->wallet_id !== (int) $wallet->id || ! $wallet->suspense_account_id) {
            throw ValidationException::withMessages(['statement_import' => 'Cartão ou conta A classificar inválidos para esta importação.']);
        }

        $targetYear ??= isset($preview['target_invoice']['reference_year']) ? (int) $preview['target_invoice']['reference_year'] : null;
        $targetMonth ??= isset($preview['target_invoice']['reference_month']) ? (int) $preview['target_invoice']['reference_month'] : null;
        if (! $targetYear || ! $targetMonth) {
            throw ValidationException::withMessages([
                'target_invoice' => 'Selecione o mês e o ano da fatura alvo antes de confirmar.',
            ]);
        }

        return DB::transaction(function () use ($wallet, $card, $preview, $contents, $filename, $decisions, $targetYear, $targetMonth) {
            $mainCard = $this->invoices->mainCard($card);
            $familyCardIds = CreditCard::query()
                ->where('wallet_id', $wallet->id)
                ->where(fn ($query) => $query->whereKey($mainCard->id)->orWhere('parent_card_id', $mainCard->id))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $parsed = $this->parser->parse($contents, $filename);
            $detectedTarget = $preview['target_invoice'] ?? null;
            $nominalDueDate = (int) ($detectedTarget['reference_year'] ?? 0) === $targetYear
                && (int) ($detectedTarget['reference_month'] ?? 0) === $targetMonth
                ? ($detectedTarget['nominal_due_at'] ?? null)
                : null;
            $invoice = $this->invoices->forReference($wallet, $mainCard, $targetYear, $targetMonth, $nominalDueDate);
            $invoice = $this->invoices->markImported($invoice);
            $decisionMap = collect($decisions)->keyBy('row_key');
            $created = 0;
            $ignored = 0;

            foreach ($preview['rows'] as $row) {
                $decision = $decisionMap->get($row['row_key']);
                $action = $decision['action'] ?? 'ignore';
                if ($action === 'ignore' || ! in_array($row['situation'], [
                    'new', 'installment_detected', 'installment_matched', 'installment_ambiguous',
                    'installment_plan_pending', 'installment_divergent',
                ], true)) {
                    $ignored++;

                    continue;
                }
                $targetCardId = in_array((int) ($row['credit_card_id'] ?? 0), $familyCardIds, true)
                    ? (int) $row['credit_card_id']
                    : $mainCard->id;
                $transaction = $parsed['transactions'][$row['index']];
                $isInstallment = (int) $row['installments_total'] > 1;
                $existingPurchase = CreditCardTransaction::query()->whereIn('credit_card_id', $familyCardIds)
                    ->where(fn ($query) => $query->where('import_hash', $row['import_hash'])
                        ->when($row['external_id'], fn ($query) => $query->orWhere(fn ($query) => $query
                            ->where('external_id', $row['external_id'])
                            ->whereDate('purchase_date', $transaction->postedAt)
                            ->where('amount_cents', $transaction->amountCents)
                            ->where('description', $transaction->description))))
                    ->with('installmentPlanItem:id,credit_card_purchase_id,installment_number')
                    ->first();
                $completePurchase = (int) ($existingPurchase?->credit_card_invoice_id ?? 0) === (int) $invoice->id
                    && (! $isInstallment
                        || (int) ($existingPurchase?->installmentPlanItem?->installment_number ?? 0) === (int) $row['installment_number']);
                if ($completePurchase) {
                    $ignored++;

                    continue;
                }

                if ($isInstallment && ! in_array($action, ['confirm_plan', 'pending_plan', 'link_plan', 'link_pending_plan', 'reconcile_divergence', 'normal'], true)) {
                    throw ValidationException::withMessages([
                        'installments' => 'Resolva todos os parcelamentos detectados antes de confirmar a importação.',
                    ]);
                }
                $entry = null;
                if (! $isInstallment || $action === 'normal') {
                    $entry = $this->journalEntries->execute([
                        'wallet_id' => $wallet->id,
                        'entry_date' => $transaction->postedAt,
                        'description' => 'Compra importada no cartão: '.$transaction->description,
                        'lines' => [
                            ['chart_of_account_id' => $wallet->suspense_account_id, 'type' => 'debit', 'amount_cents' => $transaction->amountCents],
                            ['chart_of_account_id' => $mainCard->liability_account_id, 'type' => 'credit', 'amount_cents' => $transaction->amountCents],
                        ],
                    ]);
                }
                $purchaseData = [
                    'wallet_id' => $wallet->id,
                    'credit_card_id' => $targetCardId,
                    'credit_card_invoice_id' => $invoice->id,
                    'expense_account_id' => $wallet->suspense_account_id,
                    'journal_entry_id' => $entry?->id,
                    'source' => strtolower($this->parser->format($filename)),
                    'external_id' => $row['external_id'],
                    'import_hash' => $row['import_hash'],
                    'statement_file_hash' => $preview['file_hash'] ?? null,
                    'purchase_date' => $transaction->postedAt,
                    'merchant_name' => $transaction->description,
                    'description' => $transaction->description,
                    'amount_cents' => $transaction->amountCents,
                    'installments_total' => $row['installments_total'],
                    'installment_number' => $row['installment_number'],
                    'status' => 'draft',
                ];
                if ($existingPurchase) {
                    $existingPurchase->update($purchaseData);
                    $purchase = $existingPurchase->fresh();
                } else {
                    $purchase = CreditCardTransaction::query()->create($purchaseData);
                }
                if ($isInstallment && in_array($action, ['confirm_plan', 'pending_plan'], true)) {
                    $row['statement_file_hash'] = $preview['file_hash'] ?? null;
                    $this->installmentPlans->create(
                        $wallet,
                        $mainCard,
                        CreditCard::query()->findOrFail($targetCardId),
                        $invoice,
                        $purchase,
                        $row,
                        $decision,
                    );
                } elseif ($isInstallment && $action === 'link_plan') {
                    $planId = (int) ($decision['plan_id'] ?? data_get($row, 'installment_plan_matches.0.id'));
                    $plan = \App\Models\CreditCardInstallmentPlan::query()
                        ->where('wallet_id', $wallet->id)
                        ->where('main_credit_card_id', $mainCard->id)
                        ->whereIn('id', collect($row['installment_plan_matches'] ?? [])->pluck('id'))
                        ->findOrFail($planId);
                    $this->installmentPlans->match(
                        $plan, $invoice, $purchase, (int) $row['installment_number'], (int) $row['amount_cents']
                    );
                } elseif ($isInstallment && $action === 'link_pending_plan') {
                    $planId = (int) ($decision['plan_id'] ?? data_get($row, 'installment_plan_matches.0.id'));
                    $plan = \App\Models\CreditCardInstallmentPlan::query()
                        ->where('wallet_id', $wallet->id)->where('main_credit_card_id', $mainCard->id)
                        ->whereIn('id', collect($row['installment_plan_matches'] ?? [])->pluck('id'))->findOrFail($planId);
                    $this->installmentPlans->linkForReview(
                        $plan, $invoice, $purchase, (int) $row['installment_number'], (int) $row['amount_cents'],
                        'possible_match',
                    );
                } elseif ($isInstallment && $action === 'reconcile_divergence') {
                    $planId = (int) ($decision['plan_id'] ?? data_get($row, 'installment_plan_matches.0.id'));
                    $plan = \App\Models\CreditCardInstallmentPlan::query()
                        ->where('wallet_id', $wallet->id)->where('main_credit_card_id', $mainCard->id)
                        ->whereIn('id', collect($row['installment_plan_matches'] ?? [])->pluck('id'))->findOrFail($planId);
                    $this->installmentPlans->reconcileDivergence(
                        $plan, $invoice, $purchase, (int) $row['installment_number'], (int) $row['amount_cents'],
                        (int) ($decision['expected_amount_cents'] ?? $row['amount_cents']),
                        (int) ($decision['recognized_total_cents'] ?? $plan->recognized_total_cents),
                    );
                }
                $created++;
            }

            $invoice = $this->invoices->refreshTotals($invoice);
            $previewHashes = collect($preview['rows'])
                ->where('composes_invoice_total', true)
                ->pluck('import_hash');
            $unrepresentedTotal = (int) $invoice->transactions()
                ->whereIn('status', ['draft', 'posted'])
                ->whereNotIn('import_hash', $previewHashes)
                ->sum('amount_cents');
            $expectedTotal = $unrepresentedTotal + (int) collect($preview['rows'])
                ->where('composes_invoice_total', true)
                ->sum('amount_cents');
            $missingItems = collect($preview['rows'])
                ->where('creates_financial_item', true)
                ->contains(fn (array $row) => ! CreditCardTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('credit_card_invoice_id', $invoice->id)
                    ->where('import_hash', $row['import_hash'])
                    ->exists());

            if ($missingItems || (int) $invoice->total_cents !== $expectedTotal) {
                throw ValidationException::withMessages([
                    'statement_import' => 'A importação não foi concluída porque o total confirmado diverge da prévia.',
                ]);
            }

            return ['created' => $created, 'ignored' => $ignored, 'total_cents' => (int) $invoice->total_cents];
        });
    }
}
