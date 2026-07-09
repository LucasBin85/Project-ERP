<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardInvoice extends Model
{
    protected $fillable = [
        'wallet_id',
        'credit_card_id',
        'reference_year',
        'reference_month',
        'starts_at',
        'closes_at',
        'due_at',
        'total_cents',
        'paid_cents',
        'balance_cents',
        'status',
        'closed_at',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'reference_year' => 'integer',
        'reference_month' => 'integer',
        'starts_at' => 'date',
        'closes_at' => 'date',
        'due_at' => 'date',
        'total_cents' => 'integer',
        'paid_cents' => 'integer',
        'balance_cents' => 'integer',
        'closed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditCardTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CreditCardPayment::class);
    }
}
