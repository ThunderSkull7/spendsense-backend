<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'limit_amount',
        'month_year',
    ];

    protected $casts = [
        'limit_amount' => 'decimal:2',
        'month_year'   => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
