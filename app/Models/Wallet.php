<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Accounting\CreateBaseChartOfAccounts;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'suspense_account_id',
        //'type',
        //'currency',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chartOfAccounts()
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function suspenseAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'suspense_account_id');
    }

    protected static function booted()
    {
        static::created(function (Wallet $wallet) {
            // Ao criar a carteira, gera o plano base
            app(CreateBaseChartOfAccounts::class)->handle($wallet);
        });
    }


}
