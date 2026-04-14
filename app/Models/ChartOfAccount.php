<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use HasFactory;

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

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines()
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
        return in_array($type, ['ativo', 'despesa'], true)
            ? 'debit'
            : 'credit';
    }
}