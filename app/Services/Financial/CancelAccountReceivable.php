<?php

namespace App\Services\Financial;

use App\Models\AccountReceivable;
use App\Models\FinancialTitleSeries;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\CreateProvisionCancellationReversal;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelAccountReceivable
{
    public function __construct(
        private readonly EnsureAccountingPeriodIsOpen $ensurePeriodIsOpen,
        private readonly ResizeDraftProvisionJournalEntry $resizeProvision,
        private readonly RefreshFinancialTitleSeriesStatus $refreshSeriesStatus,
        private readonly CreateProvisionCancellationReversal $createReversal,
    ) {}

    public function execute(Wallet $wallet, AccountReceivable $receivable, User $actor, string $reason, ?string $reversalDate = null): AccountReceivable
    {
        return DB::transaction(function () use ($wallet, $receivable, $actor, $reason, $reversalDate) {
            $receivable = AccountReceivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            abort_unless($receivable->wallet_id === $wallet->id, 404);

            if ($receivable->status === 'cancelled') {
                return $receivable;
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Informe o motivo do cancelamento.']);
            }
            if ($receivable->status !== 'pending' || $receivable->receipt_journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'Somente títulos pendentes e sem recebimento podem ser cancelados.']);
            }

            $series = $receivable->series_id
                ? FinancialTitleSeries::query()->whereKey($receivable->series_id)->lockForUpdate()->firstOrFail()
                : null;
            $provisionId = $series?->provision_journal_entry_id ?? $receivable->provision_journal_entry_id;
            $provision = $provisionId
                ? JournalEntry::query()->whereKey($provisionId)->lockForUpdate()->firstOrFail()
                : null;

            $reversal = null;
            if ($provision?->status === 'posted') {
                if (! $reversalDate) {
                    throw ValidationException::withMessages(['reversal_date' => 'Informe a data contábil do estorno.']);
                }
                if ($receivable->cancellation_journal_entry_id) {
                    throw ValidationException::withMessages(['provision' => 'Este título já possui um estorno de cancelamento.']);
                }
                $reversal = $this->createReversal->execute(
                    $wallet,
                    $provision,
                    $receivable->amount_cents,
                    $reversalDate,
                    "Reversão de provisão por cancelamento da conta a receber #{$receivable->id}",
                );
            } elseif ($provision) {
                $this->ensurePeriodIsOpen->handle($wallet, $provision->entry_date);
            }

            $receivable->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
                'cancellation_journal_entry_id' => $reversal?->id,
            ]);

            if ($provision && $provision->status === 'draft') {
                $remaining = $series
                    ? (int) AccountReceivable::query()->where('series_id', $series->id)->where('status', '!=', 'cancelled')->sum('amount_cents')
                    : 0;
                $this->resizeProvision->execute($provision, $remaining);
            }
            if ($series) {
                $this->refreshSeriesStatus->execute($series);
            }

            return $receivable->fresh(['cancelledBy']);
        });
    }
}
