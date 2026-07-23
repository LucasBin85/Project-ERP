<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyWalletClosing extends Model
{
    protected $fillable = ['wallet_id', 'year', 'month', 'period_start', 'period_end', 'status', 'closed_at', 'closed_by',
        'reopened_at', 'reopened_by', 'close_note', 'reopen_reason', 'snapshot_json'];

    protected $casts = ['year' => 'integer', 'month' => 'integer', 'period_start' => 'date', 'period_end' => 'date',
        'closed_at' => 'datetime', 'reopened_at' => 'datetime', 'snapshot_json' => 'array'];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
