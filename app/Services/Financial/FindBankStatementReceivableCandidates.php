<?php

namespace App\Services\Financial;

use App\Models\AccountReceivable;
use App\Models\BankAccount;
use App\Models\BankStatementImportTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class FindBankStatementReceivableCandidates
{
    /** @return Collection<int, AccountReceivable> */
    public function execute(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry): Collection
    {
        $bankLine = $this->validateMovement($wallet, $bankAccount, $entry);

        if (AccountReceivable::query()->where('receipt_journal_entry_id', $entry->id)->exists()) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Este movimento já está vinculado a uma conta a receber.']);
        }

        return AccountReceivable::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', 'pending')
            ->where('amount_cents', $bankLine->amount_cents)
            ->whereNull('receipt_journal_entry_id')
            ->whereNotNull('receivable_account_id')
            ->with(['revenueAccount:id,wallet_id,code,name,type,allows_posting', 'receivableAccount:id,wallet_id,code,name,type,financial_group,allows_posting'])
            ->get()
            ->sort(function (AccountReceivable $left, AccountReceivable $right) use ($entry) {
                $leftDistance = abs($left->due_date->startOfDay()->diffInDays($entry->entry_date->startOfDay(), false));
                $rightDistance = abs($right->due_date->startOfDay()->diffInDays($entry->entry_date->startOfDay(), false));

                return $leftDistance <=> $rightDistance
                    ?: $left->due_date->toDateString() <=> $right->due_date->toDateString()
                    ?: $left->id <=> $right->id;
            })->values();
    }

    private function validateMovement(Wallet $wallet, BankAccount $bankAccount, JournalEntry $entry): JournalLine
    {
        if ((int) $bankAccount->wallet_id !== (int) $wallet->id || (int) $entry->wallet_id !== (int) $wallet->id) {
            $this->fail('journal_entry_id', 'A conta bancária e o movimento devem pertencer à wallet ativa.');
        }
        if (! $bankAccount->is_active) {
            $this->fail('bank_account_id', 'A conta bancária precisa estar ativa para realizar o vínculo.');
        }
        if (! in_array($entry->source, OfxOperationTypePolicy::STATEMENT_IMPORT_SOURCES, true) || $entry->status !== 'draft') {
            $this->fail('journal_entry_id', 'Somente movimentos importados do extrato e em rascunho podem ser vinculados a contas a receber.');
        }

        $lines = $entry->lines()->get();
        $bankLines = $lines->where('chart_of_account_id', $bankAccount->chart_of_account_id);
        if ($bankLines->count() !== 1 || $bankLines->first()->type !== 'debit') {
            $this->fail('journal_entry_id', 'Apenas movimentos bancários de entrada podem ser vinculados a contas a receber.');
        }
        $bankLine = $bankLines->first();
        $counterparts = $lines->where('id', '!=', $bankLine->id)->values();
        if (! $wallet->suspense_account_id || $counterparts->count() !== 1
            || (int) $counterparts->first()->chart_of_account_id !== (int) $wallet->suspense_account_id
            || $counterparts->first()->type !== 'credit'
            || (int) $counterparts->first()->amount_cents !== (int) $bankLine->amount_cents) {
            $this->fail('journal_entry_id', 'O movimento deve possuir uma única contrapartida em "A classificar".');
        }

        $audit = BankStatementImportTransaction::query()
            ->where('wallet_id', $wallet->id)->where('bank_account_id', $bankAccount->id)
            ->where('journal_entry_id', $entry->id)->where('status', 'imported')
            ->where(fn ($query) => $query->whereNull('resolution')->orWhereIn('resolution', ['created', 'kept']))
            ->latest('id')->first();
        if (! $audit || ($audit->journal_line_id && (int) $audit->journal_line_id !== (int) $bankLine->id)
            || $audit->operation_type !== OfxOperationTypePolicy::INCOME || $audit->direction !== 'in'
            || (int) $audit->amount_cents !== (int) $bankLine->amount_cents
            || $audit->posted_at?->toDateString() !== $entry->entry_date?->toDateString()) {
            $this->fail('journal_entry_id', 'O movimento não é uma receita importada de entrada válida.');
        }

        return $bankLine;
    }

    /** @return never */
    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
