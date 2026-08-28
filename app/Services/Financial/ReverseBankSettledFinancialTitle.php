<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\BankStatementImportTransaction;
use App\Models\FinancialTitleSeries;
use App\Models\FinancialTitleSettlementReversal;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\CreateJournalEntry;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use App\Services\Accounting\PostJournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseBankSettledFinancialTitle
{
    public function __construct(
        private readonly EnsureAccountingPeriodIsOpen $periodGuard,
        private readonly CreateJournalEntry $createJournalEntry,
        private readonly PostJournalEntry $postJournalEntry,
        private readonly RefreshFinancialTitleSeriesStatus $refreshSeries,
    ) {}

    public function execute(
        Wallet $wallet,
        AccountPayable|AccountReceivable $title,
        User $actor,
        string $reason,
        ?string $reversalDate = null,
    ): AccountPayable|AccountReceivable {
        return DB::transaction(function () use ($wallet, $title, $actor, $reason, $reversalDate) {
            $isPayable = $title instanceof AccountPayable;
            $title = ($isPayable ? AccountPayable::query() : AccountReceivable::query())
                ->whereKey($title->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $title->wallet_id === (int) $wallet->id, 404);

            $pointer = $isPayable ? 'payment_journal_entry_id' : 'receipt_journal_entry_id';
            $settledStatus = $isPayable ? 'paid' : 'received';
            if ($title->status === 'pending' && ! $title->{$pointer} && $title->settlementReversals()->exists()) {
                return $title;
            }
            if ($title->status !== $settledStatus || ! $title->{$pointer}) {
                $this->fail('status', 'O título não possui liquidação bancária para reverter.');
            }
            $reason = trim($reason);
            if ($reason === '') {
                $this->fail('reason', 'Informe o motivo da reversão.');
            }

            $series = $title->series_id
                ? FinancialTitleSeries::query()->whereKey($title->series_id)->lockForUpdate()->firstOrFail()
                : null;
            $settlement = JournalEntry::query()->whereKey($title->{$pointer})->lockForUpdate()->firstOrFail();
            $audit = BankStatementImportTransaction::query()
                ->where('wallet_id', $wallet->id)->where('journal_entry_id', $settlement->id)
                ->latest('id')->lockForUpdate()->first();
            if (! $audit || trim((string) $settlement->source) === '' || $settlement->source === 'manual') {
                $this->fail('settlement', 'A origem bancária da liquidação não pôde ser comprovada de forma consistente.');
            }
            if ((int) $audit->bank_account_id !== (int) $title->bank_account_id || ! $wallet->suspense_account_id) {
                $this->fail('settlement', 'O vínculo bancário da liquidação está inconsistente.');
            }

            $lines = $settlement->lines()->lockForUpdate()->get();
            [$bankLine, $counterpart] = $this->canonicalLines($title, $audit, $lines, $isPayable);
            $classificationAccountSnapshot = $counterpart->chart_of_account_id;
            $adjustment = null;
            $mode = 'bank_draft_unlink';
            if ($settlement->status === 'draft') {
                $this->periodGuard->handle($wallet, $settlement->entry_date);
                $counterpart->update([
                    'chart_of_account_id' => $wallet->suspense_account_id,
                    'memo' => 'Movimento bancário pendente de classificação',
                ]);
                $audit->update(['classification_account_id' => null]);
                $settlement->recalcBalance();
                $settlement->save();
            } elseif ($settlement->status === 'posted') {
                if (! $reversalDate) {
                    $this->fail('reversal_date', 'Informe a data contábil do ajuste.');
                }
                try {
                    $adjustmentDate = CarbonImmutable::parse($reversalDate);
                } catch (\Throwable) {
                    $this->fail('reversal_date', 'Informe uma data contábil de ajuste válida.');
                }
                if ($adjustmentDate->startOfDay()->lt($settlement->entry_date->startOfDay())) {
                    $this->fail('reversal_date', 'A data do ajuste não pode ser anterior ao movimento bancário original.');
                }
                $adjustment = $this->createJournalEntry->execute([
                    'wallet_id' => $wallet->id,
                    'entry_date' => $adjustmentDate->toDateString(),
                    'description' => ($isPayable ? 'Reclassificação do pagamento bancário' : 'Reclassificação do recebimento bancário')." #{$title->id}",
                    'lines' => $isPayable ? [
                        ['chart_of_account_id' => $wallet->suspense_account_id, 'type' => 'debit', 'amount_cents' => $title->amount_cents],
                        ['chart_of_account_id' => $title->payable_account_id, 'type' => 'credit', 'amount_cents' => $title->amount_cents],
                    ] : [
                        ['chart_of_account_id' => $title->receivable_account_id, 'type' => 'debit', 'amount_cents' => $title->amount_cents],
                        ['chart_of_account_id' => $wallet->suspense_account_id, 'type' => 'credit', 'amount_cents' => $title->amount_cents],
                    ],
                ]);
                $adjustment = $this->postJournalEntry->handle($adjustment, false);
                $mode = 'bank_posted_reclassification';
            } else {
                $this->fail('settlement', 'O lançamento bancário possui status incompatível com a reversão.');
            }

            FinancialTitleSettlementReversal::query()->create([
                'wallet_id' => $wallet->id,
                'account_payable_id' => $isPayable ? $title->id : null,
                'account_receivable_id' => $isPayable ? null : $title->id,
                'settlement_journal_entry_id' => $settlement->id,
                'settlement_journal_entry_id_snapshot' => $settlement->id,
                'classification_adjustment_journal_entry_id' => $adjustment?->id,
                'bank_account_id' => $title->bank_account_id,
                'bank_statement_import_transaction_id' => $audit->id,
                'bank_journal_line_id_snapshot' => $bankLine->id,
                'classification_account_id_snapshot' => $classificationAccountSnapshot,
                'suspense_account_id_snapshot' => $wallet->suspense_account_id,
                'settlement_entry_date' => $settlement->entry_date,
                'settlement_amount_cents' => $title->amount_cents,
                'mode' => $mode,
                'reversal_date' => $adjustment?->entry_date,
                'reversed_at' => now(),
                'reversed_by_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            $title->update($isPayable ? [
                'status' => 'pending', 'paid_at' => null, 'bank_account_id' => null, 'payment_journal_entry_id' => null,
            ] : [
                'status' => 'pending', 'received_at' => null, 'bank_account_id' => null, 'receipt_journal_entry_id' => null,
            ]);
            if ($series) {
                $this->refreshSeries->execute($series);
            }

            return $title->fresh(['settlementReversals.classificationAdjustmentJournalEntry']);
        });
    }

    private function canonicalLines(AccountPayable|AccountReceivable $title, BankStatementImportTransaction $audit, $lines, bool $isPayable): array
    {
        $bankLine = $lines->firstWhere('id', $audit->journal_line_id);
        $counterpart = $lines->where('id', '!=', $audit->journal_line_id)->values();
        $controlAccountId = $isPayable ? $title->payable_account_id : $title->receivable_account_id;
        $expectedBankType = $isPayable ? 'credit' : 'debit';
        $expectedCounterpartType = $isPayable ? 'debit' : 'credit';
        if (! $bankLine || $bankLine->type !== $expectedBankType || $counterpart->count() !== 1
            || $counterpart->first()->type !== $expectedCounterpartType
            || (int) $counterpart->first()->chart_of_account_id !== (int) $controlAccountId
            || (int) $bankLine->amount_cents !== (int) $title->amount_cents
            || (int) $counterpart->first()->amount_cents !== (int) $title->amount_cents
            || (int) $audit->classification_account_id !== (int) $controlAccountId) {
            $this->fail('settlement', 'A liquidação bancária não possui a estrutura contábil esperada.');
        }

        return [$bankLine, $counterpart->first()];
    }

    /** @return never */
    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
