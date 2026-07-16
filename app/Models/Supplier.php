<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['wallet_id', 'name', 'document', 'payable_account_id', 'default_expense_account_id', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function payableAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'payable_account_id');
    }

    public function defaultExpenseAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_expense_account_id');
    }
}
