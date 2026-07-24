<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardTransaction extends Model
{
    protected $fillable = [
        'parent_transaction_id',
        'wallet_id',
        'credit_card_id',
        'credit_card_invoice_id',
        'expense_account_id',
        'journal_entry_id',
        'source',
        'external_id',
        'import_hash',
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

    public function parentTransaction(): BelongsTo
    {
        return $this->belongsTo(CreditCardTransaction::class, 'parent_transaction_id');
    }

    public function childInstallments(): HasMany
    {
        return $this->hasMany(CreditCardTransaction::class, 'parent_transaction_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function creditCardInvoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class);
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
