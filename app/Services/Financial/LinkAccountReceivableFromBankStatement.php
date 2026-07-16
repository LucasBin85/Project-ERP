<?php

namespace App\Services\Financial;

use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkAccountReceivableFromBankStatement
{
    public function execute(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry, AccountReceivable $receivable): AccountReceivable
    {
        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $receivable) {
            $entry = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ((int) $bankAccount->wallet_id !== (int) $wallet->id || (int) $entry->wallet_id !== (int) $wallet->id) {
                $this->fail('journal_entry_id', 'A conta bancária e o movimento devem pertencer à wallet ativa.');
            }
            if (! $bankAccount->is_active || $entry->source !== 'ofx' || $entry->status !== 'draft' || ! $wallet->suspense_account_id) {
                $this->fail('journal_entry_id', 'Somente movimentos OFX em rascunho da wallet ativa podem ser vinculados.');
            }
            if (AccountReceivable::query()->where('receipt_journal_entry_id', $entry->id)->lockForUpdate()->exists()) {
                $this->fail('journal_entry_id', 'Este movimento já está vinculado a outra conta a receber.');
            }

            $lines = $entry->lines()->with('chartOfAccount')->lockForUpdate()->get();
            $bankLines = $lines->where('chart_of_account_id', $bankAccount->chart_of_account_id);
            if ($bankLines->count() !== 1 || $bankLines->first()->type !== 'debit') {
                $this->fail('journal_entry_id', 'Apenas movimentos bancários de entrada podem ser vinculados a contas a receber.');
            }
            /** @var JournalLine $bankLine */
            $bankLine = $bankLines->first();
            $counterparts = $lines->where('id', '!=', $bankLine->id)->values();
            if ($counterparts->count() !== 1 || (int) $counterparts->first()->chart_of_account_id !== (int) $wallet->suspense_account_id) {
                $this->fail('journal_entry_id', 'A contrapartida do movimento não está mais pendente em "A classificar".');
            }
            /** @var JournalLine $counterpart */
            $counterpart = $counterparts->first();

            $audit = BankStatementImportTransaction::query()
                ->where('wallet_id', $wallet->id)->where('bank_account_id', $bankAccount->id)
                ->where('journal_entry_id', $entry->id)->where('status', 'imported')
                ->where(fn ($query) => $query->whereNull('resolution')->orWhereIn('resolution', ['created', 'kept']))
                ->latest('id')->lockForUpdate()->first();
            if (! $audit || ($audit->journal_line_id && (int) $audit->journal_line_id !== (int) $bankLine->id)
                || $audit->operation_type !== OfxOperationTypePolicy::INCOME || $audit->direction !== 'in'
                || (int) $audit->amount_cents !== (int) $bankLine->amount_cents
                || $audit->posted_at?->toDateString() !== $entry->entry_date?->toDateString()) {
                $this->fail('journal_entry_id', 'O movimento não é uma receita OFX de entrada válida.');
            }

            $receivable = AccountReceivable::query()->whereKey($receivable->id)->with(['revenueAccount', 'receivableAccount'])->lockForUpdate()->firstOrFail();
            if ((int) $receivable->wallet_id !== (int) $wallet->id) {
                $this->fail('account_receivable_id', 'A conta a receber deve pertencer à wallet ativa.');
            }
            if ($receivable->status !== 'pending' || $receivable->received_at || $receivable->receipt_journal_entry_id) {
                $this->fail('account_receivable_id', 'Apenas contas a receber pendentes podem ser vinculadas.');
            }
            if ((int) $receivable->amount_cents !== (int) $bankLine->amount_cents) {
                $this->fail('account_receivable_id', 'O valor da conta a receber é diferente do movimento bancário.');
            }
            $control = $receivable->receivableAccount;
            if (! $control || (int) $control->wallet_id !== (int) $wallet->id || $control->type !== 'ativo'
                || $control->financial_group !== 'accounts_receivable'
                || ! $control->isPostingAllowed() || $control->children()->exists()) {
                $this->fail('account_receivable_id', 'A conta de controle do cliente não é válida para a baixa.');
            }

            $originalBankLine = $bankLine->only(['chart_of_account_id', 'type', 'amount_cents', 'memo']);
            $counterpart->update(['chart_of_account_id' => $receivable->receivable_account_id, 'memo' => 'Conta a receber: '.$receivable->description]);
            $audit->update(['journal_line_id' => $bankLine->id, 'classification_account_id' => $receivable->receivable_account_id]);
            $entry->recalcBalance();
            if (! $entry->is_balanced || $entry->balance_diff_cents !== 0) {
                $this->fail('journal_entry_id', 'O vínculo deixou o lançamento contábil desbalanceado.');
            }
            $entry->save();
            if ($bankLine->fresh()->only(array_keys($originalBankLine)) !== $originalBankLine) {
                $this->fail('journal_entry_id', 'A linha bancária foi alterada durante o vínculo.');
            }
            $receivable->update([
                'bank_account_id' => $bankAccount->id,
                'receipt_journal_entry_id' => $entry->id,
                'received_at' => $entry->entry_date->toDateString(),
                'status' => 'received',
            ]);

            return $receivable->fresh(['revenueAccount', 'receivableAccount', 'bankAccount', 'receiptJournalEntry.lines.chartOfAccount']);
        });
    }

    /** @return never */
    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
