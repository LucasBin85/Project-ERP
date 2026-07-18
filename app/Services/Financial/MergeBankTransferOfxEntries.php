<?php

namespace App\Services\Financial;

use App\Exceptions\OfxClassificationException;
use App\Models\BankAccount;
use App\Models\BankAccountTransfer;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationStatementItem;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class MergeBankTransferOfxEntries
{
    public function __construct(private readonly FindMatchingOfxTransferEntries $matches) {}

    public function execute(Wallet $wallet, BankAccount $current, JournalEntry $entry, int $candidateAuditId): JournalEntry
    {
        return DB::transaction(function () use ($wallet, $current, $entry, $candidateAuditId) {
            $entry = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->lines()->lockForUpdate()->get();
            $transfer = BankAccountTransfer::query()->where('journal_entry_id', $entry->id)->lockForUpdate()->first();

            if (! $transfer || (int) $transfer->wallet_id !== (int) $wallet->id
                || (int) $current->wallet_id !== (int) $wallet->id || $entry->status !== 'draft'
                || $entry->source !== 'ofx' || $entry->settledAccountPayable()->exists()
                || $entry->settledAccountReceivable()->exists()) {
                throw new OfxClassificationException('Esta transferência não está disponível para vinculação.');
            }

            $candidate = $this->matches->candidates($transfer, $current, true)->firstWhere('id', $candidateAuditId);
            if (! $candidate) {
                throw new OfxClassificationException('A outra ponta selecionada não é mais compatível.');
            }

            $redundant = JournalEntry::query()->whereKey($candidate->journal_entry_id)->lockForUpdate()->firstOrFail();
            $redundantLines = $redundant->lines()->lockForUpdate()->get();
            BankStatementImportTransaction::query()->whereIn('journal_entry_id', [$entry->id, $redundant->id])->lockForUpdate()->get();

            if ($redundant->status !== 'draft' || $redundant->source !== 'ofx'
                || $redundant->settledAccountPayable()->exists() || $redundant->settledAccountReceivable()->exists()) {
                throw new OfxClassificationException('A outra ponta deixou de ser um OFX em rascunho seguro.');
            }

            $dependency = BankReconciliationItem::query()->whereIn('journal_line_id', $redundantLines->pluck('id'))->exists()
                || BankReconciliationStatementItem::query()->whereIn('journal_line_id', $redundantLines->pluck('id'))->exists();
            if ($dependency) {
                throw new OfxClassificationException('A outra ponta já possui conciliação e não pode ser mesclada.');
            }

            $counterpart = BankAccount::query()->whereKey($candidate->bank_account_id)->lockForUpdate()->firstOrFail();
            $targetLine = $entry->lines()->where('chart_of_account_id', $counterpart->chart_of_account_id)->lockForUpdate()->first();
            if (! $targetLine || $targetLine->type !== ($candidate->direction === 'in' ? 'debit' : 'credit')
                || (int) $targetLine->amount_cents !== (int) $candidate->amount_cents) {
                throw new OfxClassificationException('As linhas bancárias não formam uma transferência de direções opostas.');
            }

            $candidate->update([
                'journal_entry_id' => $entry->id,
                'journal_line_id' => $targetLine->id,
                'classification_account_id' => $current->chart_of_account_id,
                'operation_type' => OfxOperationTypePolicy::TRANSFER,
                'resolution' => 'linked',
            ]);

            $isFrom = (int) $candidate->bank_account_id === (int) $transfer->from_bank_account_id;
            $transfer->update([
                'validation_status' => 'fully_validated',
                $isFrom ? 'from_import_transaction_id' : 'to_import_transaction_id' => $candidate->id,
            ]);

            $redundant->delete();

            return $entry->fresh(['lines.chartOfAccount']);
        });
    }
}
