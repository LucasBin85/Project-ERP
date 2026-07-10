<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    protected $fillable = [
        'wallet_id',
        'bank_account_id',
        'source',
        'original_filename',
        'file_hash',
        'statement_started_at',
        'statement_ended_at',
        'total_transactions',
        'imported_transactions',
        'skipped_duplicates',
        'total_in_cents',
        'total_out_cents',
        'status',
        'error_message',
    ];

    protected $casts = [
        'statement_started_at' => 'date',
        'statement_ended_at' => 'date',
        'total_transactions' => 'integer',
        'imported_transactions' => 'integer',
        'skipped_duplicates' => 'integer',
        'total_in_cents' => 'integer',
        'total_out_cents' => 'integer',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankStatementImportTransaction::class);
    }
}
