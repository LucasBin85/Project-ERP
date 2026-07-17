<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccountTransfer extends Model
{
    protected $fillable = [
        'wallet_id', 'journal_entry_id', 'from_bank_account_id', 'to_bank_account_id',
        'from_journal_line_id', 'to_journal_line_id', 'amount_cents', 'transfer_date',
        'validation_status', 'from_import_transaction_id', 'to_import_transaction_id',
    ];

    protected $casts = ['amount_cents' => 'integer', 'transfer_date' => 'date'];

    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
    public function fromBankAccount() { return $this->belongsTo(BankAccount::class, 'from_bank_account_id'); }
    public function toBankAccount() { return $this->belongsTo(BankAccount::class, 'to_bank_account_id'); }
    public function fromJournalLine() { return $this->belongsTo(JournalLine::class, 'from_journal_line_id'); }
    public function toJournalLine() { return $this->belongsTo(JournalLine::class, 'to_journal_line_id'); }
}
