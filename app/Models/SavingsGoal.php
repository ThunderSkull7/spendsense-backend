<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavingsGoal extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'target_amount',
        'target_date',
        'status',
        'notes',
        'completed_at',
        'archived_at',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'target_date'   => 'date',
        'completed_at'  => 'datetime',
        'archived_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contributions()
    {
        return $this->hasMany(SavingsGoalContribution::class);
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
