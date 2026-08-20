<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecurringFinancialExpectation extends Model
{
    public const FREQUENCY_INTERVALS = [
        'monthly' => 1,
        'quarterly' => 3,
        'semiannual' => 6,
        'annual' => 12,
    ];

    protected $fillable = [
        'wallet_id',
        'replaces_expectation_id',
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
        'schedule_anchor_date',
        'ends_on',
        'status',
        'notes',
    ];

    protected $casts = [
        'interval_months' => 'integer',
        'due_day' => 'integer',
        'expected_amount_cents' => 'integer',
        'starts_on' => 'date',
        'schedule_anchor_date' => 'date',
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

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_expectation_id');
    }

    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_expectation_id');
    }

    public function scheduleAnchorDate(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->schedule_anchor_date ?? $this->starts_on)->startOfMonth();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function intervalMonthsFor(string $frequency): ?int
    {
        return self::FREQUENCY_INTERVALS[$frequency] ?? null;
    }

    public function isApplicableTo(CarbonInterface $period): bool
    {
        if (! $this->isActive()) {
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

        return $this->scheduleAnchorDate()->diffInMonths($periodMonth, true) % max(1, $this->interval_months) === 0;
    }

    public function dueDateForPeriod(CarbonInterface $period): CarbonImmutable
    {
        $periodMonth = CarbonImmutable::instance($period)->startOfMonth();

        return $periodMonth->setDay(min($this->due_day, $periodMonth->daysInMonth));
    }

    public function counterpartyName(): ?string
    {
        return $this->type === 'payable'
            ? $this->supplier?->name
            : $this->customer?->name;
    }
}
