<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPayable extends Model
{
    protected $table = 'accounts_payable';

    protected $fillable = [
        'wallet_id',
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
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'date',
        'amount_cents' => 'integer',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
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
}
