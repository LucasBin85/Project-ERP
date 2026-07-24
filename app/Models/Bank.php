<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $fillable = [
        'code',
        'name',
        'short_name',
        'ispb',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function creditCards(): HasMany
    {
        return $this->hasMany(CreditCard::class, 'issuer_bank_id');
    }
}
