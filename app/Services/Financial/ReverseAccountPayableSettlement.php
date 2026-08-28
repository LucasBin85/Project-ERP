<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
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

class ReverseAccountPayableSettlement
{
    public function __construct(
        private readonly EnsureAccountingPeriodIsOpen $periodGuard,
        private readonly CreateCanonicalJournalEntryReversal $createReversal,
        private readonly RefreshFinancialTitleSeriesStatus $refreshSeries,
        private readonly ReverseBankSettledFinancialTitle $reverseBankSettlement,
    ) {}

    public function execute(Wallet $wallet, AccountPayable $payable, User $actor, string $reason, ?string $reversalDate = null): AccountPayable
    {
        $pointer = AccountPayable::query()->whereKey($payable->id)->value('payment_journal_entry_id');
        if ($pointer && JournalEntry::query()->whereKey($pointer)->whereHas('bankStatementImportTransaction')->exists()) {
            return $this->reverseBankSettlement->execute($wallet, $payable, $actor, $reason, $reversalDate);
        }

        return DB::transaction(function () use ($wallet, $payable, $actor, $reason, $reversalDate) {
            $payable = AccountPayable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();
            abort_unless($payable->wallet_id === $wallet->id, 404);
            if ($payable->status === 'pending' && ! $payable->payment_journal_entry_id && $payable->settlementReversals()->exists()) {
                return $payable;
            }
            if ($payable->status !== 'paid' || ! $payable->payment_journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'O título não possui pagamento para reverter.']);
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Informe o motivo da reversão.']);
            }
            $series = $payable->series_id
                ? FinancialTitleSeries::query()->whereKey($payable->series_id)->lockForUpdate()->firstOrFail()
                : null;
            $settlement = JournalEntry::query()->whereKey($payable->payment_journal_entry_id)->lockForUpdate()->firstOrFail();
            $this->assertManual($settlement);
            $this->assertStructure($payable, $settlement);
            if (FinancialTitleSettlementReversal::query()->where('settlement_journal_entry_id_snapshot', $settlement->id)->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Este pagamento já foi revertido.']);
            }

            $reversal = null;
            $mode = 'draft_void';
            if ($settlement->status === 'posted') {
                if (! $reversalDate) {
                    throw ValidationException::withMessages(['reversal_date' => 'Informe a data contábil do estorno.']);
                }
                $reversal = $this->createReversal->execute(
                    $wallet, $settlement, $payable->amount_cents, $reversalDate,
                    "Estorno do pagamento da conta a pagar #{$payable->id}",
                );
                $mode = 'posted_reversal';
            } else {
                $this->periodGuard->handle($wallet, $settlement->entry_date);
            }

            FinancialTitleSettlementReversal::query()->create([
                'wallet_id' => $wallet->id, 'account_payable_id' => $payable->id,
                'settlement_journal_entry_id' => $settlement->id,
                'settlement_journal_entry_id_snapshot' => $settlement->id,
                'reversal_journal_entry_id' => $reversal?->id, 'bank_account_id' => $payable->bank_account_id,
                'settlement_entry_date' => $settlement->entry_date, 'settlement_amount_cents' => $payable->amount_cents,
                'mode' => $mode, 'reversal_date' => $reversal?->entry_date,
                'reversed_at' => now(), 'reversed_by_user_id' => $actor->id, 'reason' => $reason,
            ]);
            $payable->update([
                'status' => 'pending', 'paid_at' => null, 'bank_account_id' => null, 'payment_journal_entry_id' => null,
            ]);
            if ($settlement->status === 'draft') {
                $settlement->delete();
            }
            if ($series) {
                $this->refreshSeries->execute($series);
            }

            return $payable->fresh(['settlementReversals.reversalJournalEntry']);
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

    private function assertStructure(AccountPayable $payable, JournalEntry $settlement): void
    {
        $bankAccountId = $payable->bankAccount()->value('chart_of_account_id');
        $lines = $settlement->lines()->get();
        $valid = $lines->count() === 2
            && $lines->where('chart_of_account_id', $payable->payable_account_id)->where('type', 'debit')->sum('amount_cents') === $payable->amount_cents
            && $lines->where('chart_of_account_id', $bankAccountId)->where('type', 'credit')->sum('amount_cents') === $payable->amount_cents;
        if (! $valid) {
            throw ValidationException::withMessages(['settlement' => 'O pagamento não possui a estrutura contábil manual esperada.']);
        }
    }
}
