<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTitleSettlementReversal extends Model
{
    protected $fillable = [
        'wallet_id', 'account_payable_id', 'account_receivable_id',
        'settlement_journal_entry_id', 'settlement_journal_entry_id_snapshot', 'reversal_journal_entry_id',
        'bank_account_id', 'settlement_entry_date', 'settlement_amount_cents', 'mode',
        'reversal_date', 'reversed_at', 'reversed_by_user_id', 'reason',
    ];

    protected $casts = [
        'settlement_entry_date' => 'date',
        'settlement_amount_cents' => 'integer',
        'reversal_date' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class);
    }

    public function settlementJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }
}
