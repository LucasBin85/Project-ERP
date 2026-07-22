<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementClassificationRule extends Model
{
    protected $fillable = ['wallet_id', 'name', 'match_text', 'match_mode', 'direction', 'operation_type', 'chart_of_account_id', 'bank_account_id', 'supplier_id', 'customer_id', 'investment_account_id', 'active', 'priority'];

    protected $casts = ['active' => 'boolean', 'priority' => 'integer'];

    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    public function chartOfAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function investmentAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'investment_account_id'); }
}
