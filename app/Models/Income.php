<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'source',
        'income_date',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'income_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
