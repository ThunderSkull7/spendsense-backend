<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BudgetAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'budget_id',
        'threshold',
        'period_month',
        'spent_amount',
        'limit_amount',
        'read_at',
    ];

    protected $casts = [
        'threshold'    => 'integer',
        'period_month' => 'date',
        'spent_amount' => 'decimal:2',
        'limit_amount' => 'decimal:2',
        'read_at'      => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
