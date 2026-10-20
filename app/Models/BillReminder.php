<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillReminder extends Model
{
    use HasFactory;

    public const TYPES = [
        'upcoming',
        'due',
        'overdue',
    ];

    protected $fillable = [
        'user_id',
        'recurring_expense_occurrence_id',
        'type',
        'window_days',
        'due_date',
        'read_at',
    ];

    protected $casts = [
        'window_days' => 'integer',
        'due_date' => 'date',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function occurrence()
    {
        return $this->belongsTo(RecurringExpenseOccurrence::class, 'recurring_expense_occurrence_id');
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
