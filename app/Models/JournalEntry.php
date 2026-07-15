<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'source',
        'external_id',
        'entry_date',
        'description',
        'status',
        'posted_at',
        'is_balanced',
        'balance_diff_cents',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'is_balanced' => 'boolean',
        'balance_diff_cents' => 'integer',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function settledAccountPayable(): HasOne
    {
        return $this->hasOne(AccountPayable::class, 'payment_journal_entry_id');
    }

    /**
     * Recalcula is_balanced e balance_diff_cents (debit - credit)
     */
    public function recalcBalance(): void
    {
        $debits = (int) $this->lines()->where('type', 'debit')->sum('amount_cents');
        $credits = (int) $this->lines()->where('type', 'credit')->sum('amount_cents');

        $diff = $debits - $credits;

        // diff == 0 e tem ao menos algum valor lançado
        $this->is_balanced = ($diff === 0) && (($debits + $credits) > 0);
        $this->balance_diff_cents = $diff;
    }
}
