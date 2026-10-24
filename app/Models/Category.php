<?php

namespace App\Models;

use App\Support\DefaultCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    use HasFactory;

    /**
     * Unowned categories are shared defaults. Existing legacy rows that are
     * not part of the canonical default set keep is_default = false and are
     * deliberately not treated as selectable categories.
     */
    protected $attributes = [
        'is_default' => true,
    ];

    protected $fillable = [
        'name',
        'type',
        'user_id',
        'is_default',
        'default_key',
    ];

    protected $hidden = [
        'default_key',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if ($category->user_id !== null) {
                $category->is_default = false;
                $category->default_key = null;

                return;
            }

            if ($category->is_default && blank($category->default_key)) {
                $category->default_key = DefaultCategories::key(
                    (string) $category->type,
                    (string) $category->name
                );
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }

    public function recurringExpenses()
    {
        return $this->hasMany(RecurringExpense::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Categories a user may select for a new transaction or budget:
     * every shared default plus the user's own private categories.
     *
     * The optional allowId keeps an existing, otherwise-unavailable legacy
     * category attached to a historical record during an unrelated edit.
     */
    public function scopeAvailableTo(
        Builder $query,
        int $userId,
        ?string $type = null,
        ?int $allowId = null
    ): Builder {
        return $query
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->where(function (Builder $query) use ($userId, $allowId): void {
                $query->where(function (Builder $query): void {
                    $query->whereNull('user_id')->where('is_default', true);
                })->orWhere('user_id', $userId);

                if ($allowId !== null) {
                    // Only an unowned legacy row may stay attached for
                    // historical edits. Never extend this exception to another
                    // account's private category.
                    $query->orWhere(function (Builder $query) use ($allowId): void {
                        $query->whereKey($allowId)
                            ->whereNull('user_id')
                            ->where('is_default', false);
                    });
                }
            });
    }

    public function isDefault(): bool
    {
        return $this->is_default === true && $this->user_id === null;
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->user_id === $userId && ! $this->is_default;
    }
}
