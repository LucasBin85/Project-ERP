<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    protected $fillable = [
        'wallet_id',
        'parent_id',
        'code',
        'name',
        'type',
        'normal_balance',
        'is_system',
        'allows_posting',
        'financial_group',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'allows_posting' => 'boolean',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'chart_of_account_id');
    }

    public static function financialGroups(): array
    {
        return [
            'available',
            'investments',
            'accounts_receivable',
            'accounts_payable',
        ];
    }

    public static function normalBalanceByType(string $type): string
    {
        return in_array($type, ['ativo', 'despesa'], true) ? 'debit' : 'credit';
    }

    public function isPostingAllowed(): bool
    {
        return (bool) $this->allows_posting;
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    public function isSynthetic(): bool
    {
        return ! $this->isPostingAllowed();
    }

    public function canBeDeleted(): bool
    {
        if ($this->isSystem()) {
            return false;
        }

        if ($this->children()->exists()) {
            return false;
        }

        if ($this->journalLines()->exists()) {
            return false;
        }

        return true;
    }
}