<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringIncome extends Model
{
    use HasFactory;

    public const FREQUENCIES = [
        'daily',
        'weekly',
        'biweekly',
        'monthly',
        'quarterly',
        'half-yearly',
        'yearly',
    ];

    public const STATUSES = [
        'active',
        'paused',
        'stopped',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'amount',
        'frequency',
        'start_date',
        'end_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function occurrences()
    {
        return $this->hasMany(RecurringIncomeOccurrence::class)
            ->orderBy('occurrence_date');
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
