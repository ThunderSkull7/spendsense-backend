<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringExpenseOccurrence extends Model
{
    use HasFactory;

    public const STATUSES = [
        'planned',
        'paid',
        'skipped',
        'cancelled',
    ];

    protected $fillable = [
        'recurring_expense_id',
        'occurrence_date',
        'expected_amount',
        'status',
        'expense_id',
        'paid_at',
    ];

    protected $casts = [
        'occurrence_date' => 'date',
        'expected_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function schedule()
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
    }

    /**
     * Paid occurrences intentionally include a soft-deleted expense so history
     * remains visible while the expense is in the existing trash/restore flow.
     */
    public function expense()
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    public function reminders()
    {
        return $this->hasMany(BillReminder::class);
    }
}
