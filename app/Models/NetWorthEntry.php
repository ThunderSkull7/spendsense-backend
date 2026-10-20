<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NetWorthEntry extends Model
{
    use HasFactory;

    public const TYPES = [
        'asset',
        'liability',
    ];

    public const STATUSES = [
        'active',
        'archived',
    ];

    public const ASSET_CATEGORIES = [
        'cash',
        'investment',
        'other_asset',
    ];

    public const LIABILITY_CATEGORIES = [
        'loan',
        'other_liability',
    ];

    public const CATEGORIES = [
        'cash',
        'investment',
        'other_asset',
        'loan',
        'other_liability',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'category',
        'institution',
        'notes',
        'status',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function valuations()
    {
        return $this->hasMany(NetWorthValuation::class)->orderByDesc('valuation_date')->orderByDesc('id');
    }

    public function scopeWhereUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public static function categoriesFor(string $type): array
    {
        return $type === 'liability' ? self::LIABILITY_CATEGORIES : self::ASSET_CATEGORIES;
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'cash' => 'Cash and savings',
            'investment' => 'Investments',
            'other_asset' => 'Other assets',
            'loan' => 'Loan',
            'other_liability' => 'Other liabilities',
            default => $category,
        };
    }
}
