<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['wallet_id', 'name', 'document', 'receivable_account_id', 'default_revenue_account_id', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function receivableAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'receivable_account_id');
    }

    public function defaultRevenueAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_revenue_account_id');
    }
}
