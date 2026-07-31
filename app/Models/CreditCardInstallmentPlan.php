<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardInstallmentPlan extends Model
{
    protected $fillable = [
        'wallet_id', 'main_credit_card_id', 'child_credit_card_id', 'description_base',
        'normalized_description', 'total_installments', 'first_known_installment',
        'recognized_from_installment', 'recognized_to_installment', 'original_total_cents',
        'recognized_total_cents', 'installment_amount_cents', 'classification_account_id',
        'recognition_journal_entry_id', 'recognition_date', 'started_before_erp', 'status',
        'source', 'metadata_json', 'notes',
    ];

    protected $casts = [
        'total_installments' => 'integer', 'first_known_installment' => 'integer',
        'recognized_from_installment' => 'integer', 'recognized_to_installment' => 'integer',
        'original_total_cents' => 'integer', 'recognized_total_cents' => 'integer',
        'installment_amount_cents' => 'integer', 'recognition_date' => 'date',
        'started_before_erp' => 'boolean', 'metadata_json' => 'array',
    ];

    public function mainCreditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'main_credit_card_id');
    }

    public function childCreditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'child_credit_card_id');
    }

    public function classificationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'classification_account_id');
    }

    public function recognitionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'recognition_journal_entry_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditCardInstallmentPlanItem::class, 'plan_id');
    }
}
