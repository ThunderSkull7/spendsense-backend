<?php

namespace App\Models;

use App\Services\RecurrenceDateService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    public const STATUSES = [
        'active',
        'paused',
        'cancelled',
    ];

    public const FREQUENCIES = RecurrenceDateService::FREQUENCIES;

    protected $fillable = [
        'user_id',
        'name',
        'provider',
        'recurring_expense_id',
        'amount',
        'frequency',
        'next_renewal_date',
        'category_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'next_renewal_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recurringExpense()
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    public function schedule()
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isLinked(): bool
    {
        return $this->recurring_expense_id !== null;
    }
}
