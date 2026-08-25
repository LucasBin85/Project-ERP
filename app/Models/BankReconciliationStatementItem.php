<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationStatementItem extends Model
{
    protected static function booted(): void
    {
        $guard = function (BankReconciliationStatementItem $item) {
            $reconciliationId = $item->bank_reconciliation_id ?: $item->getOriginal('bank_reconciliation_id');

            if (BankReconciliation::query()->whereKey($reconciliationId)->where('status', 'completed')->exists()) {
                throw new \DomainException('Os itens de uma conciliação concluída são imutáveis.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected $fillable = [
        'bank_reconciliation_id',
        'bank_statement_import_transaction_id',
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

    public function bankStatementImportTransaction(): BelongsTo
    {
        return $this->belongsTo(BankStatementImportTransaction::class);
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
