<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkAccountPayableFromBankStatement
{
    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
        AccountPayable $accountPayable,
    ): AccountPayable {
        return DB::transaction(function () use ($wallet, $bankAccount, $entry, $accountPayable) {
            $entry = JournalEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateEntry($wallet, $bankAccount, $entry);

            $existingSettlement = AccountPayable::query()
                ->where('payment_journal_entry_id', $entry->id)
                ->lockForUpdate()
                ->first(['id']);

            if ($existingSettlement) {
                $this->fail('journal_entry_id', 'Este movimento já está vinculado a outra conta a pagar.');
            }

            $lines = $entry->lines()
                ->with('chartOfAccount')
                ->lockForUpdate()
                ->get();
            $bankLines = $lines->where('chart_of_account_id', $bankAccount->chart_of_account_id);

            if ($bankLines->count() !== 1) {
                $this->fail('journal_entry_id', 'A linha bancária do movimento não pôde ser identificada de forma única.');
            }

            /** @var JournalLine $bankLine */
            $bankLine = $bankLines->first();

            if ($bankLine->type !== 'credit') {
                $this->fail(
                    'journal_entry_id',
                    'Apenas movimentos bancários de saída podem ser vinculados a contas a pagar.',
                );
            }

            $counterpartLines = $lines
                ->reject(fn (JournalLine $line) => (int) $line->id === (int) $bankLine->id)
                ->values();

            if ($counterpartLines->count() !== 1) {
                $this->fail(
                    'journal_entry_id',
                    'O movimento deve possuir uma única contrapartida para receber a despesa do título.',
                );
            }

            /** @var JournalLine $counterpartLine */
            $counterpartLine = $counterpartLines->first();

            if ((int) $counterpartLine->chart_of_account_id !== (int) $wallet->suspense_account_id) {
                $this->fail(
                    'journal_entry_id',
                    'A contrapartida do movimento não está mais pendente em "A classificar".',
                );
            }

            $auditTransaction = BankStatementImportTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('bank_account_id', $bankAccount->id)
                ->where('journal_entry_id', $entry->id)
                ->where('status', 'imported')
                ->where(function ($query) {
                    $query->whereNull('resolution')
                        ->orWhereIn('resolution', ['created', 'kept']);
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $this->validateAuditTransaction($auditTransaction, $entry, $bankLine);

            $accountPayable = AccountPayable::query()
                ->whereKey($accountPayable->id)
                ->with(['expenseAccount', 'payableAccount'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateAccountPayable(
                $wallet,
                $entry,
                $bankLine,
                $accountPayable,
            );

            $originalBankLine = $bankLine->only([
                'chart_of_account_id',
                'type',
                'amount_cents',
                'memo',
            ]);

            $counterpartLine->update([
                'chart_of_account_id' => $accountPayable->payable_account_id,
                'memo' => 'Conta a pagar: '.$accountPayable->description,
            ]);

            $auditTransaction->update([
                'journal_line_id' => $bankLine->id,
                'classification_account_id' => $accountPayable->payable_account_id,
            ]);

            $entry->recalcBalance();

            if (! $entry->is_balanced || $entry->balance_diff_cents !== 0) {
                $this->fail('journal_entry_id', 'O vínculo deixou o lançamento contábil desbalanceado.');
            }

            $entry->save();

            $bankLine->refresh();

            if ($bankLine->only(array_keys($originalBankLine)) !== $originalBankLine) {
                $this->fail('journal_entry_id', 'A linha bancária foi alterada durante o vínculo.');
            }

            $accountPayable->update([
                'bank_account_id' => $bankAccount->id,
                'payment_journal_entry_id' => $entry->id,
                'paid_at' => $entry->entry_date->toDateString(),
                'status' => 'paid',
            ]);

            return $accountPayable->fresh([
                'expenseAccount',
                'payableAccount',
                'bankAccount',
                'paymentJournalEntry.lines.chartOfAccount',
            ]);
        });
    }

    private function validateEntry(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry): void
    {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id
            || (int) $entry->wallet_id !== (int) $wallet->id) {
            $this->fail(
                'journal_entry_id',
                'A conta bancária e o movimento devem pertencer à wallet ativa.',
            );
        }

        if (! $bankAccount->is_active) {
            $this->fail('bank_account_id', 'A conta bancária precisa estar ativa para realizar o vínculo.');
        }

        if (! in_array($entry->source, ['ofx', 'csv', 'pdf'], true) || $entry->status !== 'draft') {
            $this->fail(
                'journal_entry_id',
                'Somente movimentos OFX em rascunho podem ser vinculados a contas a pagar.',
            );
        }

        if (! $wallet->suspense_account_id) {
            $this->fail('journal_entry_id', 'A wallet ativa não possui conta "A classificar" definida.');
        }
    }

    private function validateAuditTransaction(
        ?BankStatementImportTransaction $auditTransaction,
        JournalEntry $entry,
        JournalLine $bankLine,
    ): void {
        if (! $auditTransaction
            || ($auditTransaction->journal_line_id
                && (int) $auditTransaction->journal_line_id !== (int) $bankLine->id)
            || $auditTransaction->operation_type !== OfxOperationTypePolicy::PAYMENT
            || $auditTransaction->direction !== 'out'
            || (int) $auditTransaction->amount_cents !== (int) $bankLine->amount_cents
            || $auditTransaction->posted_at?->toDateString() !== $entry->entry_date?->toDateString()) {
            $this->fail('journal_entry_id', 'O movimento não é um pagamento OFX de saída válido.');
        }
    }

    private function validateAccountPayable(
        Wallet $wallet,
        JournalEntry $entry,
        JournalLine $bankLine,
        AccountPayable $accountPayable,
    ): void {
        if ((int) $accountPayable->wallet_id !== (int) $wallet->id) {
            $this->fail('account_payable_id', 'A conta a pagar deve pertencer à wallet ativa.');
        }

        if ($accountPayable->status !== 'pending'
            || $accountPayable->paid_at
            || $accountPayable->payment_journal_entry_id) {
            $this->fail('account_payable_id', 'Apenas contas a pagar pendentes podem ser vinculadas.');
        }

        if ((int) $accountPayable->amount_cents !== (int) $bankLine->amount_cents) {
            $this->fail('account_payable_id', 'O valor da conta a pagar é diferente do movimento bancário.');
        }

        $payableAccount = $accountPayable->payableAccount;

        if (! $payableAccount
            || (int) $payableAccount->wallet_id !== (int) $wallet->id
            || $payableAccount->type !== 'passivo'
            || $payableAccount->financial_group !== 'accounts_payable'
            || ! $payableAccount->isPostingAllowed()
            || $payableAccount->children()->exists()) {
            $this->fail('account_payable_id', 'A conta de controle do fornecedor não é válida para a baixa.');
        }

        if ($entry->entry_date === null) {
            $this->fail('journal_entry_id', 'O movimento bancário não possui data para a liquidação.');
        }
    }

    /** @return never */
    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
