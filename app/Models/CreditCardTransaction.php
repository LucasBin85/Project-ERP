<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCardTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'credit_card_id',
        'expense_account_id',
        'journal_entry_id',
        'purchase_date',
        'merchant_name',
        'description',
        'amount_cents',
        'installments_total',
        'installment_number',
        'status',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'amount_cents' => 'integer',
        'installments_total' => 'integer',
        'installment_number' => 'integer',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
