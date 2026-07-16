<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

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

    public function scopeValidForReceivables(Builder $query, int $walletId): Builder
    {
        return $query->where('wallet_id', $walletId)
            ->where('active', true)
            ->whereHas('receivableAccount', fn (Builder $account) => $account
                ->where('wallet_id', $walletId)
                ->where('type', 'ativo')
                ->where('financial_group', 'accounts_receivable')
                ->where('allows_posting', true)
                ->whereDoesntHave('children'))
            ->whereHas('defaultRevenueAccount', fn (Builder $account) => $account
                ->where('wallet_id', $walletId)
                ->where('type', 'receita')
                ->where('allows_posting', true)
                ->whereDoesntHave('children'));
    }
}
