<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransfer extends Model
{
    protected $fillable = [
        'wallet_id',
        'from_bank_account_id',
        'to_bank_account_id',
        'journal_entry_id',
        'transfer_date',
        'amount_cents',
        'description',
        'status',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'amount_cents' => 'integer',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function fromBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_bank_account_id');
    }

    public function toBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_bank_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
