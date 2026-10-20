<?php

namespace App\Models;

use App\Services\RecurrenceDateService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringExpense extends Model
{
    use HasFactory;

    public const FREQUENCIES = RecurrenceDateService::FREQUENCIES;

    public const STATUSES = [
        'active',
        'paused',
        'stopped',
    ];

    protected $fillable = [
        'user_id',
        'description',
        'amount',
        'category_id',
        'frequency',
        'start_date',
        'end_date',
        'status',
        'reminder_days_before',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'reminder_days_before' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function occurrences()
    {
        return $this->hasMany(RecurringExpenseOccurrence::class)->orderBy('occurrence_date');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
