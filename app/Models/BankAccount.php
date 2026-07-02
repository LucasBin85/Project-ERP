<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $fillable = [
        'wallet_id',
        'chart_of_account_id',
        'name',
        'bank_name',
        'bank_code',
        'agency',
        'account_number',
        'account_type',
        'opening_balance_cents',
        'is_active',
    ];

    protected $casts = [
        'opening_balance_cents' => 'integer',
        'is_active' => 'boolean',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }
}