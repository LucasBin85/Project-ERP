<?php

namespace App\Services\Financial;

use App\Exceptions\OfxClassificationException;
use App\Models\BankAccount;
use App\Models\BankAccountTransfer;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;

class ClassifyBankStatementTransfer
{
    public function execute(Wallet $wallet, BankAccount $current, JournalEntry $entry, int $counterpartChartAccountId): JournalEntry
    {
        $counterpart = BankAccount::query()->where('wallet_id', $wallet->id)->where('is_active', true)
            ->where('chart_of_account_id', $counterpartChartAccountId)->first();

        if (! $counterpart || (int) $counterpart->id === (int) $current->id) {
            throw new OfxClassificationException('Selecione outra conta bancária ativa da wallet como contraparte.');
        }
        if ($entry->source !== 'ofx' || $entry->status !== 'draft' || $entry->settledAccountPayable()->exists() || $entry->settledAccountReceivable()->exists()) {
            throw new OfxClassificationException('Este lançamento não pode ser classificado como transferência.');
        }
        if (BankAccountTransfer::query()->where('journal_entry_id', $entry->id)->exists()) {
            throw new OfxClassificationException('Esta transferência já foi registrada.');
        }

        $lines = $entry->lines()->lockForUpdate()->get();
        $currentLine = $lines->firstWhere('chart_of_account_id', $current->chart_of_account_id);
        $classificationLine = $lines->first(fn (JournalLine $line) => (int) $line->chart_of_account_id === (int) $wallet->suspense_account_id);
        if (! $currentLine || ! $classificationLine || $lines->count() !== 2 || $currentLine->type === $classificationLine->type) {
            throw new OfxClassificationException('O lançamento deve possuir uma linha bancária e uma contrapartida em "A classificar".');
        }

        $audit = BankStatementImportTransaction::query()->where('wallet_id', $wallet->id)
            ->where('bank_account_id', $current->id)->where('journal_entry_id', $entry->id)
            ->where('status', 'imported')->lockForUpdate()->first();
        if (! $audit) throw new OfxClassificationException('A transferência deve ter origem em uma importação OFX válida.');

        $classificationLine->update(['chart_of_account_id' => $counterpart->chart_of_account_id, 'memo' => 'Transferência bancária: '.$counterpart->name]);
        $audit->update(['journal_line_id' => $currentLine->id, 'classification_account_id' => $counterpart->chart_of_account_id, 'operation_type' => OfxOperationTypePolicy::TRANSFER]);
        $isOut = $currentLine->type === 'credit';

        BankAccountTransfer::query()->create([
            'wallet_id' => $wallet->id, 'journal_entry_id' => $entry->id,
            'from_bank_account_id' => $isOut ? $current->id : $counterpart->id,
            'to_bank_account_id' => $isOut ? $counterpart->id : $current->id,
            'from_journal_line_id' => $isOut ? $currentLine->id : $classificationLine->id,
            'to_journal_line_id' => $isOut ? $classificationLine->id : $currentLine->id,
            'amount_cents' => $currentLine->amount_cents, 'transfer_date' => $entry->entry_date,
            'validation_status' => 'pending_counterpart_ofx',
            'from_import_transaction_id' => $isOut ? $audit->id : null,
            'to_import_transaction_id' => $isOut ? null : $audit->id,
        ]);

        $entry->recalcBalance();
        $entry->save();
        return $entry->fresh(['lines.chartOfAccount']);
    }
}
