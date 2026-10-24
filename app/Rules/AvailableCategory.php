<?php

namespace App\Rules;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AvailableCategory implements ValidationRule
{
    /**
     * @param  int  $userId  The authenticated owner whose private categories are allowed.
     * @param  int|null  $allowId  An existing record's category, allowed for historical edits.
     */
    public function __construct(
        private readonly int $userId,
        private readonly ?int $allowId = null
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $exists = Category::query()
            ->availableTo($this->userId, null, $this->allowId)
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail('The selected category is not available to this account.');
        }
    }
}
