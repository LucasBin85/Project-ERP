<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationStatementItem extends Model
{
    protected $fillable = [
        'bank_reconciliation_id',
        'journal_line_id',
        'transaction_date',
        'description',
        'amount_cents',
        'status',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount_cents' => 'integer',
    ];

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
