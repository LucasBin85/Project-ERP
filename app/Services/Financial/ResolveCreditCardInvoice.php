<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

class ResolveCreditCardInvoice
{
    public function forPurchaseDate(Wallet $wallet, CreditCard $card, string $purchaseDate): CreditCardInvoice
    {
        $mainCard = $this->mainCard($card);
        $purchase = CarbonImmutable::parse($purchaseDate)->startOfDay();
        $closingDay = (int) $mainCard->closing_day;

        $reference = $purchase->day <= $closingDay
            ? $purchase
            : $purchase->addMonthNoOverflow();

        $closesAt = $this->dateForDay($reference, $closingDay);
        $previousClose = $this->dateForDay($reference->subMonthNoOverflow(), $closingDay);
        $startsAt = $previousClose->addDay();
        $dueAt = $this->dueDate($closesAt, (int) $mainCard->due_day);

        return CreditCardInvoice::query()->firstOrCreate(
            [
                'credit_card_id' => $mainCard->id,
                'reference_year' => (int) $closesAt->year,
                'reference_month' => (int) $closesAt->month,
            ],
            [
                'wallet_id' => $wallet->id,
                'starts_at' => $startsAt->toDateString(),
                'closes_at' => $closesAt->toDateString(),
                'due_at' => $dueAt->toDateString(),
                'total_cents' => 0,
                'paid_cents' => 0,
                'balance_cents' => 0,
                'status' => 'open',
            ],
        );
    }

    public function refreshTotals(CreditCardInvoice $invoice): CreditCardInvoice
    {
        $total = (int) $invoice->transactions()
            ->whereIn('status', ['draft', 'posted'])
            ->sum('amount_cents');

        $paid = (int) $invoice->payments()
            ->whereIn('status', ['draft', 'posted'])
            ->sum('amount_cents');

        $balance = $total - $paid;
        $status = $this->statusFor($invoice, $total, $paid, $balance);

        $invoice->update([
            'total_cents' => $total,
            'paid_cents' => $paid,
            'balance_cents' => $balance,
            'status' => $status,
            'closed_at' => in_array($status, ['closed', 'partial', 'paid', 'overdue'], true)
                ? ($invoice->closed_at ?? now())
                : null,
            'paid_at' => $status === 'paid'
                ? ($invoice->paid_at ?? now())
                : null,
        ]);

        return $invoice->fresh(['transactions', 'payments']);
    }

    public function mainCard(CreditCard $card): CreditCard
    {
        if ($card->parent_card_id) {
            return $card->parentCard()->firstOrFail();
        }

        return $card;
    }

    private function dateForDay(CarbonImmutable $date, int $day): CarbonImmutable
    {
        $day = min($day, $date->daysInMonth);

        return $date->setDay($day)->startOfDay();
    }

    private function dueDate(CarbonImmutable $closesAt, int $dueDay): CarbonImmutable
    {
        $dueMonth = $dueDay > $closesAt->day
            ? $closesAt
            : $closesAt->addMonthNoOverflow();

        return $this->dateForDay($dueMonth, $dueDay);
    }

    private function statusFor(CreditCardInvoice $invoice, int $total, int $paid, int $balance): string
    {
        if ($total <= 0) {
            return 'open';
        }

        if ($balance <= 0) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partial';
        }

        if (CarbonImmutable::parse($invoice->due_at)->isPast()) {
            return 'overdue';
        }

        if (CarbonImmutable::parse($invoice->closes_at)->isPast()) {
            return 'closed';
        }

        return 'open';
    }
}
