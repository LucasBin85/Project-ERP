<?php

namespace App\Services\Financial;

use App\Exceptions\OfxClassificationException;
use App\Models\BankAccount;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class ResolveOfxDraftMatch
{
    public function __construct(
        private readonly FindMatchingOfxJournalLine $matchingJournalLines,
    ) {}

    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
        string $action,
        ?int $candidateJournalLineId = null,
    ): ?JournalEntry {
        return DB::transaction(function () use (
            $wallet,
            $bankAccount,
            $entry,
            $action,
            $candidateJournalLineId,
        ) {
            $entry = JournalEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $bankAccount->wallet_id !== (int) $wallet->id
                || (int) $entry->wallet_id !== (int) $wallet->id
                || $entry->source !== 'ofx'
                || $entry->status !== 'draft') {
                throw new OfxClassificationException(
                    'Somente um lançamento OFX em rascunho da wallet ativa pode resolver vínculos.',
                );
            }

            $lines = $entry->lines()->lockForUpdate()->get();
            $bankLines = $lines->where('chart_of_account_id', $bankAccount->chart_of_account_id);

            if ($bankLines->count() !== 1) {
                throw new OfxClassificationException('A linha bancária do lançamento OFX não pôde ser identificada.');
            }

            /** @var JournalLine $bankLine */
            $bankLine = $bankLines->first();
            $auditTransaction = BankStatementImportTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('bank_account_id', $bankAccount->id)
                ->where('journal_entry_id', $entry->id)
                ->where(function ($query) use ($bankLine, $entry) {
                    $query->where('journal_line_id', $bankLine->id)
                        ->orWhere(function ($query) use ($bankLine, $entry) {
                            $query->whereNull('journal_line_id')
                                ->whereDate('posted_at', $entry->entry_date->toDateString())
                                ->where('amount_cents', $bankLine->amount_cents)
                                ->where('direction', $bankLine->type === 'debit' ? 'in' : 'out');
                        });
                })
                ->where(function ($query) {
                    $query->where('status', 'imported')
                        ->orWhere(function ($query) {
                            $query->where('status', 'skipped_duplicate')
                                ->whereNull('resolution');
                        });
                })
                ->where(function ($query) {
                    $query->whereNull('resolution')
                        ->orWhere('resolution', 'created');
                })
                ->orderByRaw("CASE WHEN status = 'imported' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $auditTransaction) {
                throw new OfxClassificationException('Este lançamento OFX já teve seu vínculo resolvido.');
            }

            if (! $auditTransaction->journal_line_id) {
                $auditTransaction->journal_line_id = $bankLine->id;
            }

            $candidates = $this->matchingJournalLines->candidates(
                wallet: $wallet,
                bankAccount: $bankAccount,
                entryDate: $entry->entry_date->toDateString(),
                amountCents: (int) $bankLine->amount_cents,
                direction: $bankLine->type === 'debit' ? 'in' : 'out',
                lockForUpdate: true,
            );

            if ($candidates->isEmpty()) {
                throw new OfxClassificationException('Nenhum lançamento manual compatível está disponível para decisão.');
            }

            if ($action === 'keep') {
                $auditTransaction->status = 'imported';
                $auditTransaction->resolution = 'kept';
                $auditTransaction->save();

                return $entry->fresh(['lines.chartOfAccount']);
            }

            if ($action !== 'link' || ! $candidateJournalLineId) {
                throw new OfxClassificationException('Selecione uma decisão de vínculo válida.');
            }

            /** @var JournalLine|null $candidate */
            $candidate = $candidates->firstWhere('id', $candidateJournalLineId);

            if (! $candidate || ! $candidate->journalEntry) {
                throw new OfxClassificationException('O lançamento manual selecionado não é mais compatível.');
            }

            $lineIds = $lines->pluck('id');
            $hasReconciliationDependency = BankReconciliationItem::query()
                ->whereIn('journal_line_id', $lineIds)
                ->exists()
                || BankReconciliationStatementItem::query()
                    ->whereIn('journal_line_id', $lineIds)
                    ->exists();

            if ($hasReconciliationDependency) {
                throw new OfxClassificationException(
                    'O lançamento OFX já possui referência de conciliação e não pode ser substituído automaticamente.',
                );
            }

            $manualEntry = $candidate->journalEntry;
            $auditTransaction->update([
                'journal_entry_id' => $manualEntry->id,
                'journal_line_id' => $candidate->id,
                'classification_account_id' => null,
                'operation_type' => null,
                'status' => 'imported',
                'resolution' => 'linked',
            ]);

            $entry->delete();

            return $manualEntry->fresh(['lines.chartOfAccount']);
        });
    }
}
