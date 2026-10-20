<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringIncomeOccurrence extends Model
{
    use HasFactory;

    public const STATUSES = [
        'planned',
        'received',
        'cancelled',
    ];

    protected $fillable = [
        'recurring_income_id',
        'occurrence_date',
        'expected_amount',
        'status',
        'income_id',
    ];

    protected $casts = [
        'occurrence_date' => 'date',
        'expected_amount' => 'decimal:2',
    ];

    public function schedule()
    {
        return $this->belongsTo(RecurringIncome::class, 'recurring_income_id');
    }

    public function income()
    {
        return $this->belongsTo(Income::class);
    }

    /**
     * Planned dates in the past are shown as overdue without changing the
     * stored status (which remains "planned" until received or cancelled).
     */
    public function displayStatus(): string
    {
        $today = CarbonImmutable::today('UTC')->toDateString();

        if ($this->status === 'planned' && $this->occurrence_date->toDateString() < $today) {
            return 'overdue';
        }

        return $this->status;
    }
}
