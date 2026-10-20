<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavingsGoalContribution extends Model
{
    use HasFactory;

    public const TYPE_CONTRIBUTION = 'contribution';

    public const TYPE_CORRECTION = 'correction';

    protected $fillable = [
        'savings_goal_id',
        'type',
        'amount',
        'note',
        'contributed_at',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'contributed_at' => 'date',
    ];

    public function goal()
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }
}
