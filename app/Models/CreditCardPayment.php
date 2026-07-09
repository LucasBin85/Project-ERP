<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCardPayment extends Model
{
    protected $fillable = [
        'wallet_id',
        'credit_card_id',
        'credit_card_invoice_id',
        'bank_account_id',
        'journal_entry_id',
        'payment_date',
        'amount_cents',
        'description',
        'status',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_cents' => 'integer',
    ];

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

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
