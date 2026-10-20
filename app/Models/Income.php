<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Supported frequency values for an income entry.
     * "one-time" is the default recorded by the UI; the selector only
     * describes the income — entries are never generated automatically.
     */
    public const FREQUENCIES = [
        'one-time',
        'daily',
        'weekly',
        'biweekly',
        'monthly',
        'quarterly',
        'half-yearly',
        'yearly',
    ];

    protected $fillable = [
        'user_id',
        'amount',
        'source',
        'income_date',
        'frequency',
        'notes',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'income_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recurringOccurrence()
    {
        return $this->hasOne(RecurringIncomeOccurrence::class);
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
