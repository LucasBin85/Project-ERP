<?php

namespace App\Services\Financial;

use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\Wallet;

class ResolveCreditCardInvoiceForPurchaseDate
{
    public function __construct(private readonly ResolveCreditCardInvoice $invoices) {}

    public function execute(Wallet $wallet, CreditCard $card, string $purchaseDate): CreditCardInvoice
    {
        return $this->invoices->forPurchaseDate($wallet, $card, $purchaseDate);
    }
}
