<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class FindBankStatementPayableCandidates
{
    /** @return Collection<int, AccountPayable> */
    public function execute(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
    ): Collection {
        $bankLine = $this->validateMovement($wallet, $bankAccount, $entry);

        if (AccountPayable::query()
            ->where('payment_journal_entry_id', $entry->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'Este movimento já está vinculado a uma conta a pagar.',
            ]);
        }

        /** @var Collection<int, AccountPayable> $candidates */
        $candidates = AccountPayable::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'pending')
            ->where('amount_cents', $bankLine->amount_cents)
            ->whereNull('payment_journal_entry_id')
            ->whereNotNull('payable_account_id')
            ->with(['expenseAccount:id,wallet_id,code,name,type,allows_posting', 'payableAccount:id,wallet_id,code,name,type,financial_group,allows_posting'])
            ->get()
            ->sort(function (AccountPayable $left, AccountPayable $right) use ($entry) {
                $leftDistance = abs(
                    $left->due_date->startOfDay()->diffInDays($entry->entry_date->startOfDay(), false),
                );
                $rightDistance = abs(
                    $right->due_date->startOfDay()->diffInDays($entry->entry_date->startOfDay(), false),
                );

                return $leftDistance <=> $rightDistance
                    ?: $left->due_date->toDateString() <=> $right->due_date->toDateString()
                    ?: $left->id <=> $right->id;
            })
            ->values();

        return $candidates;
    }

    private function validateMovement(
        Wallet $wallet,
        BankAccount $bankAccount,
        JournalEntry $entry,
    ): JournalLine {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id
            || (int) $entry->wallet_id !== (int) $wallet->id) {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'A conta bancária e o movimento devem pertencer à wallet ativa.',
            ]);
        }

        if (! $bankAccount->is_active) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'A conta bancária precisa estar ativa para realizar o vínculo.',
            ]);
        }

        if ($entry->source !== 'ofx' || $entry->status !== 'draft') {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'Somente movimentos OFX em rascunho podem ser vinculados a contas a pagar.',
            ]);
        }

        if (! $wallet->suspense_account_id) {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'A wallet ativa não possui conta "A classificar" definida.',
            ]);
        }

        $lines = $entry->lines()->get();
        $bankLines = $lines->where('chart_of_account_id', $bankAccount->chart_of_account_id);

        if ($bankLines->count() !== 1) {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'A linha bancária do movimento não pôde ser identificada de forma única.',
            ]);
        }

        /** @var JournalLine $bankLine */
        $bankLine = $bankLines->first();

        if ($bankLine->type !== 'credit') {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'Apenas movimentos bancários de saída podem ser vinculados a contas a pagar.',
            ]);
        }

        $counterpartLines = $lines
            ->reject(fn (JournalLine $line) => (int) $line->id === (int) $bankLine->id)
            ->values();

        if ($counterpartLines->count() !== 1
            || (int) $counterpartLines->first()->chart_of_account_id !== (int) $wallet->suspense_account_id
            || $counterpartLines->first()->type !== 'debit'
            || (int) $counterpartLines->first()->amount_cents !== (int) $bankLine->amount_cents) {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'O movimento deve possuir uma única contrapartida em "A classificar".',
            ]);
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
            ->first();

        if (! $auditTransaction
            || ($auditTransaction->journal_line_id
                && (int) $auditTransaction->journal_line_id !== (int) $bankLine->id)
            || $auditTransaction->operation_type !== OfxOperationTypePolicy::PAYMENT
            || $auditTransaction->direction !== 'out'
            || (int) $auditTransaction->amount_cents !== (int) $bankLine->amount_cents
            || $auditTransaction->posted_at?->toDateString() !== $entry->entry_date?->toDateString()) {
            throw ValidationException::withMessages([
                'journal_entry_id' => 'O movimento não é um pagamento OFX de saída válido.',
            ]);
        }

        return $bankLine;
    }
}
