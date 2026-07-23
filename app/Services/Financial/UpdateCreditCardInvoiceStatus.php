<?php

namespace App\Services\Financial;

use App\Models\CreditCardInvoice;

class UpdateCreditCardInvoiceStatus
{
    public function __construct(private readonly ResolveCreditCardInvoice $invoices) {}

    public function execute(CreditCardInvoice $invoice): CreditCardInvoice
    {
        return $this->invoices->refreshTotals($invoice);
    }
}
