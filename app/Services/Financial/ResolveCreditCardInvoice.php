<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

class ResolveCreditCardInvoice
{
    public function __construct(private readonly ResolveCreditCardCycleDates $cycles) {}

    public function forPurchaseDate(Wallet $wallet, CreditCard $card, string $purchaseDate): CreditCardInvoice
    {
        $mainCard = $this->mainCard($card);
        $dates = $this->cycles->forPurchaseDate($mainCard, $purchaseDate);

        return $this->forDates($wallet, $mainCard, $dates);
    }

    public function forReference(Wallet $wallet, CreditCard $card, int $year, int $month, ?string $nominalDueDate = null): CreditCardInvoice
    {
        $mainCard = $this->mainCard($card);

        return $this->forDates($wallet, $mainCard, $this->cycles->forReference($mainCard, $year, $month, $nominalDueDate));
    }

    private function forDates(Wallet $wallet, CreditCard $mainCard, array $dates): CreditCardInvoice
    {
        $invoice = CreditCardInvoice::query()->firstOrCreate(
            [
                'credit_card_id' => $mainCard->id,
                'reference_year' => $dates['reference_year'],
                'reference_month' => $dates['reference_month'],
            ],
            [
                'wallet_id' => $wallet->id,
                'starts_at' => $dates['starts_at']->toDateString(),
                'closes_at' => $dates['closes_at']->toDateString(),
                'nominal_due_at' => $dates['nominal_due_at']->toDateString(),
                'due_at' => $dates['due_at']->toDateString(),
                'total_cents' => 0,
                'paid_cents' => 0,
                'balance_cents' => 0,
                'status' => 'open',
            ],
        );

        if (! $invoice->nominal_due_at) {
            $invoice->update([
                'nominal_due_at' => $dates['nominal_due_at']->toDateString(),
                'due_at' => $dates['due_at']->toDateString(),
            ]);
        }

        return $invoice;
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
