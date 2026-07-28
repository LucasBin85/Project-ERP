<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialTitleSeries extends Model
{
    protected $table = 'financial_title_series';

    protected $fillable = [
        'wallet_id', 'type', 'mode', 'description', 'counterparty',
        'total_amount_cents', 'installment_count', 'competence_date',
        'provision_journal_entry_id', 'status',
    ];

    protected $casts = [
        'total_amount_cents' => 'integer',
        'installment_count' => 'integer',
        'competence_date' => 'date',
    ];

    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    public function provisionJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'provision_journal_entry_id'); }
    public function payables(): HasMany { return $this->hasMany(AccountPayable::class, 'series_id')->orderBy('installment_number'); }
    public function receivables(): HasMany { return $this->hasMany(AccountReceivable::class, 'series_id')->orderBy('installment_number'); }
}
