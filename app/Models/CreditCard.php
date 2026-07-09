<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCard extends Model
{
    protected $fillable = [
        'wallet_id',
        'liability_account_id',
        'bank_account_id',
        'parent_card_id',
        'name',
        'issuer_name',
        'network',
        'card_type',
        'holder_name',
        'last_four',
        'closing_day',
        'due_day',
        'best_purchase_day',
        'credit_limit_cents',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'closing_day' => 'integer',
        'due_day' => 'integer',
        'best_purchase_day' => 'integer',
        'credit_limit_cents' => 'integer',
        'is_active' => 'boolean',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'liability_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function parentCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'parent_card_id');
    }

    public function childCards(): HasMany
    {
        return $this->hasMany(CreditCard::class, 'parent_card_id');
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
