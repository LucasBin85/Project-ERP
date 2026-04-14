<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccountTemplate extends Model
{
    protected $fillable = ['parent_id', 'code', 'name', 'type'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccountTemplate::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccountTemplate::class, 'parent_id');
    }
}
