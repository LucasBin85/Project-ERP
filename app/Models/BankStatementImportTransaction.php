<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementImportTransaction extends Model
{
    protected $fillable = [
        'bank_statement_import_id',
        'wallet_id',
        'bank_account_id',
        'journal_entry_id',
        'journal_line_id',
        'external_id',
        'fit_id',
        'posted_at',
        'description',
        'amount_cents',
        'direction',
        'status',
        'raw_payload',
        'error_message',
    ];

    protected $casts = [
        'posted_at' => 'date',
        'amount_cents' => 'integer',
        'raw_payload' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
