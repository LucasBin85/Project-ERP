<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReceivable extends Model
{
    protected $table = 'accounts_receivable';

    protected $fillable = [
        'wallet_id',
        'revenue_account_id',
        'bank_account_id',
        'receipt_journal_entry_id',
        'customer_name',
        'description',
        'due_date',
        'received_at',
        'amount_cents',
        'status',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'received_at' => 'date',
        'amount_cents' => 'integer',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function receiptJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'receipt_journal_entry_id');
    }
}
