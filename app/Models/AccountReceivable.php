<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReceivable extends Model
{
    protected $table = 'accounts_receivable';

    protected $fillable = [
        'wallet_id',
        'series_id',
        'installment_number',
        'installment_count',
        'customer_id',
        'receivable_account_id',
        'revenue_account_id',
        'provision_journal_entry_id',
        'bank_account_id',
        'receipt_journal_entry_id',
        'customer_name',
        'description',
        'due_date',
        'received_at',
        'amount_cents',
        'status',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'received_at' => 'date',
        'amount_cents' => 'integer',
        'installment_number' => 'integer',
        'installment_count' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(FinancialTitleSeries::class, 'series_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receivable_account_id');
    }

    public function provisionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'provision_journal_entry_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function receiptJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'receipt_journal_entry_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
