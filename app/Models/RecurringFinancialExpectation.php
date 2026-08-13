<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringFinancialExpectation extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',
        'supplier_id',
        'customer_id',
        'description',
        'frequency',
        'interval_months',
        'due_day',
        'amount_mode',
        'expected_amount_cents',
        'default_account_id',
        'starts_on',
        'ends_on',
        'status',
        'notes',
    ];

    protected $casts = [
        'interval_months' => 'integer',
        'due_day' => 'integer',
        'expected_amount_cents' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_account_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringFinancialOccurrence::class);
    }

    public function isApplicableTo(CarbonInterface $period): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $periodMonth = CarbonImmutable::instance($period)->startOfMonth();
        $startMonth = CarbonImmutable::instance($this->starts_on)->startOfMonth();
        $endMonth = $this->ends_on
            ? CarbonImmutable::instance($this->ends_on)->startOfMonth()
            : null;

        if ($periodMonth->lt($startMonth) || ($endMonth && $periodMonth->gt($endMonth))) {
            return false;
        }

        return $startMonth->diffInMonths($periodMonth) % max(1, $this->interval_months) === 0;
    }

    public function dueDateForPeriod(CarbonInterface $period): CarbonImmutable
    {
        $periodMonth = CarbonImmutable::instance($period)->startOfMonth();
        $day = min($this->due_day, $periodMonth->daysInMonth);

        return $periodMonth->setDay($day);
    }

    public function counterpartyName(): string
    {
        return $this->type === 'payable'
            ? (string) $this->supplier?->name
            : (string) $this->customer?->name;
    }
}
