<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    //
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'parent_id',
        'code',
        'name',
        'type',
        'is_protected',
    ];

    protected $casts = [
        'is_protected' => 'boolean',
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
}
