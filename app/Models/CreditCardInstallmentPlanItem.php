<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCardInstallmentPlanItem extends Model
{
    protected $fillable = [
        'plan_id', 'installment_number', 'amount_cents', 'expected_invoice_year',
        'expected_invoice_month', 'expected_due_at', 'credit_card_invoice_id',
        'credit_card_purchase_id', 'status', 'source', 'matched_at', 'metadata_json',
    ];

    protected $casts = [
        'installment_number' => 'integer', 'amount_cents' => 'integer',
        'expected_invoice_year' => 'integer', 'expected_invoice_month' => 'integer',
        'expected_due_at' => 'date', 'matched_at' => 'datetime', 'metadata_json' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CreditCardInstallmentPlan::class, 'plan_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreditCardTransaction::class, 'credit_card_purchase_id');
    }
}
