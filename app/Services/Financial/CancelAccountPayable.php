<?php

namespace App\Services\Financial;

use App\Models\AccountPayable;
use App\Models\FinancialTitleSeries;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Accounting\EnsureAccountingPeriodIsOpen;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelAccountPayable
{
    public function __construct(
        private readonly EnsureAccountingPeriodIsOpen $ensurePeriodIsOpen,
        private readonly ResizeDraftProvisionJournalEntry $resizeProvision,
        private readonly RefreshFinancialTitleSeriesStatus $refreshSeriesStatus,
    ) {}

    public function execute(Wallet $wallet, AccountPayable $payable, User $actor, string $reason): AccountPayable
    {
        return DB::transaction(function () use ($wallet, $payable, $actor, $reason) {
            $payable = AccountPayable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();
            abort_unless($payable->wallet_id === $wallet->id, 404);

            if ($payable->status === 'cancelled') {
                return $payable;
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Informe o motivo do cancelamento.']);
            }
            if ($payable->status !== 'pending' || $payable->payment_journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'Somente títulos pendentes e sem pagamento podem ser cancelados.']);
            }

            $series = $payable->series_id
                ? FinancialTitleSeries::query()->whereKey($payable->series_id)->lockForUpdate()->firstOrFail()
                : null;
            $provisionId = $series?->provision_journal_entry_id ?? $payable->provision_journal_entry_id;
            $provision = $provisionId
                ? JournalEntry::query()->whereKey($provisionId)->lockForUpdate()->firstOrFail()
                : null;

            if ($provision?->status === 'posted') {
                throw ValidationException::withMessages(['provision' => 'A provisão já foi contabilizada e exige reversão contábil.']);
            }
            if ($provision) {
                $this->ensurePeriodIsOpen->handle($wallet, $provision->entry_date);
            }

            $payable->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            if ($provision) {
                $remaining = $series
                    ? (int) AccountPayable::query()->where('series_id', $series->id)->where('status', '!=', 'cancelled')->sum('amount_cents')
                    : 0;
                $this->resizeProvision->execute($provision, $remaining);
            }
            if ($series) {
                $this->refreshSeriesStatus->execute($series);
            }

            return $payable->fresh(['cancelledBy']);
        });
    }
}
