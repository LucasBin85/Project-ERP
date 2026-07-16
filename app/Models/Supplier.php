<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

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

    public function scopeValidForPayables(Builder $query, int $walletId): Builder
    {
        return $query->where('wallet_id', $walletId)
            ->where('active', true)
            ->whereHas('payableAccount', fn (Builder $account) => $account
                ->where('wallet_id', $walletId)
                ->where('type', 'passivo')
                ->where('financial_group', 'accounts_payable')
                ->where('allows_posting', true)
                ->whereDoesntHave('children'))
            ->whereHas('defaultExpenseAccount', fn (Builder $account) => $account
                ->where('wallet_id', $walletId)
                ->where('type', 'despesa')
                ->where('allows_posting', true)
                ->whereDoesntHave('children'));
    }
}
