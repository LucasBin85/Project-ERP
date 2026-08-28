<?php

namespace App\Services\Financial;

use App\Models\AccountReceivable;
use App\Models\BankStatementImportTransaction;
use App\Models\FinancialTitleSeries;
use App\Models\FinancialTitleSettlementReversal;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\CreateCanonicalJournalEntryReversal;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseAccountReceivableSettlement
{
    public function __construct(
        private readonly EnsureAccountingPeriodIsOpen $periodGuard,
        private readonly CreateCanonicalJournalEntryReversal $createReversal,
        private readonly RefreshFinancialTitleSeriesStatus $refreshSeries,
        private readonly ReverseBankSettledFinancialTitle $reverseBankSettlement,
    ) {}

    public function execute(Wallet $wallet, AccountReceivable $receivable, User $actor, string $reason, ?string $reversalDate = null): AccountReceivable
    {
        $pointer = AccountReceivable::query()->whereKey($receivable->id)->value('receipt_journal_entry_id');
        if ($pointer && JournalEntry::query()->whereKey($pointer)->whereHas('bankStatementImportTransaction')->exists()) {
            return $this->reverseBankSettlement->execute($wallet, $receivable, $actor, $reason, $reversalDate);
        }

        return DB::transaction(function () use ($wallet, $receivable, $actor, $reason, $reversalDate) {
            $receivable = AccountReceivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            abort_unless($receivable->wallet_id === $wallet->id, 404);
            if ($receivable->status === 'pending' && ! $receivable->receipt_journal_entry_id && $receivable->settlementReversals()->exists()) {
                return $receivable;
            }
            if ($receivable->status !== 'received' || ! $receivable->receipt_journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'O título não possui recebimento para reverter.']);
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Informe o motivo da reversão.']);
            }
            $series = $receivable->series_id
                ? FinancialTitleSeries::query()->whereKey($receivable->series_id)->lockForUpdate()->firstOrFail()
                : null;
            $settlement = JournalEntry::query()->whereKey($receivable->receipt_journal_entry_id)->lockForUpdate()->firstOrFail();
            $this->assertManual($settlement);
            $this->assertStructure($receivable, $settlement);
            if (FinancialTitleSettlementReversal::query()->where('settlement_journal_entry_id_snapshot', $settlement->id)->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Este recebimento já foi revertido.']);
            }

            $reversal = null;
            $mode = 'draft_void';
            if ($settlement->status === 'posted') {
                if (! $reversalDate) {
                    throw ValidationException::withMessages(['reversal_date' => 'Informe a data contábil do estorno.']);
                }
                $reversal = $this->createReversal->execute(
                    $wallet, $settlement, $receivable->amount_cents, $reversalDate,
                    "Estorno do recebimento da conta a receber #{$receivable->id}",
                );
                $mode = 'posted_reversal';
            } else {
                $this->periodGuard->handle($wallet, $settlement->entry_date);
            }

            FinancialTitleSettlementReversal::query()->create([
                'wallet_id' => $wallet->id, 'account_receivable_id' => $receivable->id,
                'settlement_journal_entry_id' => $settlement->id,
                'settlement_journal_entry_id_snapshot' => $settlement->id,
                'reversal_journal_entry_id' => $reversal?->id, 'bank_account_id' => $receivable->bank_account_id,
                'settlement_entry_date' => $settlement->entry_date, 'settlement_amount_cents' => $receivable->amount_cents,
                'mode' => $mode, 'reversal_date' => $reversal?->entry_date,
                'reversed_at' => now(), 'reversed_by_user_id' => $actor->id, 'reason' => $reason,
            ]);
            $receivable->update([
                'status' => 'pending', 'received_at' => null, 'bank_account_id' => null, 'receipt_journal_entry_id' => null,
            ]);
            if ($settlement->status === 'draft') {
                $settlement->delete();
            }
            if ($series) {
                $this->refreshSeries->execute($series);
            }

            return $receivable->fresh(['settlementReversals.reversalJournalEntry']);
        });
    }

    private function assertManual(JournalEntry $settlement): void
    {
        if ($settlement->source !== 'manual' || BankStatementImportTransaction::query()->where('journal_entry_id', $settlement->id)->exists()) {
            throw ValidationException::withMessages([
                'settlement' => 'Esta liquidação está vinculada a um movimento bancário e exige o fluxo específico de reversão bancária.',
            ]);
        }
    }

    private function assertStructure(AccountReceivable $receivable, JournalEntry $settlement): void
    {
        $bankAccountId = $receivable->bankAccount()->value('chart_of_account_id');
        $lines = $settlement->lines()->get();
        $valid = $lines->count() === 2
            && $lines->where('chart_of_account_id', $bankAccountId)->where('type', 'debit')->sum('amount_cents') === $receivable->amount_cents
            && $lines->where('chart_of_account_id', $receivable->receivable_account_id)->where('type', 'credit')->sum('amount_cents') === $receivable->amount_cents;
        if (! $valid) {
            throw ValidationException::withMessages(['settlement' => 'O recebimento não possui a estrutura contábil manual esperada.']);
        }
    }
}
