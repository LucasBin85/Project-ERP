<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPayable extends Model
{
    protected $table = 'accounts_payable';

    protected $fillable = [
        'wallet_id',
        'series_id',
        'installment_number',
        'installment_count',
        'supplier_id',
        'payable_account_id',
        'expense_account_id',
        'provision_journal_entry_id',
        'bank_account_id',
        'payment_journal_entry_id',
        'payee_name',
        'description',
        'due_date',
        'paid_at',
        'amount_cents',
        'status',
        'cancellation_journal_entry_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'date',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'payable_account_id');
    }

    public function provisionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'provision_journal_entry_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function cancellationJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'cancellation_journal_entry_id');
    }
}
