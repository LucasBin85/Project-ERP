<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    protected static function booted(): void
    {
        static::updating(function (BankReconciliation $reconciliation) {
            if ($reconciliation->getOriginal('status') === 'completed') {
                throw new \DomainException('Uma conciliação concluída é imutável.');
            }
        });

        static::deleting(function (BankReconciliation $reconciliation) {
            if ($reconciliation->status === 'completed') {
                throw new \DomainException('Uma conciliação concluída não pode ser excluída.');
            }
        });
    }

    protected $fillable = [
        'wallet_id',
        'bank_account_id',
        'period_start',
        'period_end',
        'opening_balance_cents',
        'statement_balance_cents',
        'book_balance_cents',
        'reconciled_balance_cents',
        'difference_cents',
        'status',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'opening_balance_cents' => 'integer',
        'statement_balance_cents' => 'integer',
        'book_balance_cents' => 'integer',
        'reconciled_balance_cents' => 'integer',
        'difference_cents' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BankReconciliationItem::class);
    }

    public function statementItems(): HasMany
    {
        return $this->hasMany(BankReconciliationStatementItem::class);
    }
}
