<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringFinancialOccurrence extends Model
{
    protected $fillable = [
        'wallet_id',
        'recurring_financial_expectation_id',
        'period_date',
        'due_date',
        'expected_amount_cents',
        'actual_amount_cents',
        'status',
        'account_payable_id',
        'account_receivable_id',
        'confirmed_at',
        'skipped_at',
        'notes',
    ];

    protected $casts = [
        'period_date' => 'date',
        'due_date' => 'date',
        'expected_amount_cents' => 'integer',
        'actual_amount_cents' => 'integer',
        'confirmed_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function expectation(): BelongsTo
    {
        return $this->belongsTo(RecurringFinancialExpectation::class, 'recurring_financial_expectation_id');
    }

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class);
    }
}
