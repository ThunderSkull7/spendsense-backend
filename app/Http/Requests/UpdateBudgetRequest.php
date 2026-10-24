<?php

namespace App\Http\Requests;

use App\Models\Budget;
use App\Rules\AvailableCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $thresholds = $this->input('alert_thresholds');

        if (is_string($thresholds)) {
            $thresholds = array_values(array_filter(array_map(
                fn (string $value) => trim($value),
                explode(',', $thresholds)
            ), fn (string $value) => $value !== ''));

            $this->merge([
                'alert_thresholds' => array_map(
                    fn ($value) => is_numeric($value) ? (int) $value : $value,
                    $thresholds
                ),
            ]);
        } elseif (is_array($thresholds)) {
            $this->merge([
                'alert_thresholds' => array_map(
                    fn ($value) => is_numeric($value) ? (int) $value : $value,
                    $thresholds
                ),
            ]);
        }

        $month = $this->input('month_year');

        if (! is_string($month)) {
            return;
        }

        $normalized = $this->firstDayOfMonth($month);

        if ($normalized !== null) {
            $this->merge(['month_year' => $normalized]);
        }
    }

    public function rules(): array
    {
        $budget = $this->route('budget');
        $currentCategoryId = $budget instanceof Budget
            ? (int) $budget->category_id
            : null;

        return [
            'category_id' => [
                'sometimes',
                'integer',
                new AvailableCategory(
                    (int) $this->user()->id,
                    $currentCategoryId
                ),
            ],
            'limit_amount' => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'month_year' => 'sometimes|date_format:Y-m-d',
            'alert_thresholds' => 'sometimes|required|array|min:1|max:5',
            'alert_thresholds.*' => 'integer|between:1,100|distinct',
        ];
    }

    /**
     * Validate the final category/month pair, including fields omitted from
     * this request that will retain their current values.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $budget = $this->route('budget');
                if (! $budget instanceof Budget) {
                    return;
                }

                $categoryId = $this->input('category_id', $budget->category_id);
                $month = (string) $this->input('month_year', $budget->month_year->toDateString());
                [$year, $monthNumber] = array_map('intval', explode('-', $month));

                $duplicate = Budget::query()
                    ->where('user_id', $this->user()->id)
                    ->where('category_id', $categoryId)
                    ->whereYear('month_year', $year)
                    ->whereMonth('month_year', $monthNumber)
                    ->whereKeyNot($budget->id)
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add(
                        'category_id',
                        'A budget already exists for this category and month.'
                    );
                }
            },
        ];
    }

    private function firstDayOfMonth(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) {
            return $matches[1].'-'.$matches[2].'-01';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return $matches[1].'-'.$matches[2].'-01';
        }

        return null;
    }
}
