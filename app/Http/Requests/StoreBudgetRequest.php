<?php

namespace App\Http\Requests;

use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeThresholds();

        // <input type="month"> submits YYYY-MM, but the API also accepts a full
        // date. Both are normalized to the first day so the one-budget-per-
        // calendar-month rule is deterministic and matches the database index.
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
        return [
            'category_id' => 'required|integer|exists:categories,id',
            'limit_amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'month_year' => 'required|date_format:Y-m-d',
            'alert_thresholds' => 'nullable|array|min:1|max:5',
            'alert_thresholds.*' => 'integer|between:1,100|distinct',
        ];
    }

    private function normalizeThresholds(): void
    {
        $thresholds = $this->input('alert_thresholds');

        if (is_string($thresholds)) {
            $thresholds = array_values(array_filter(array_map(
                fn (string $value) => trim($value),
                explode(',', $thresholds)
            ), fn (string $value) => $value !== ''));
        }

        if (is_array($thresholds)) {
            $this->merge([
                'alert_thresholds' => array_map(
                    fn ($value) => is_numeric($value) ? (int) $value : $value,
                    $thresholds
                ),
            ]);
        }
    }

    /**
     * Check the category/month pair only after basic validation, so a
     * malformed date can never be sent to PostgreSQL's date comparison.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $month = (string) $this->input('month_year');
                [$year, $monthNumber] = array_map('intval', explode('-', $month));

                $duplicate = Budget::query()
                    ->where('user_id', $this->user()->id)
                    ->where('category_id', $this->input('category_id'))
                    ->whereYear('month_year', $year)
                    ->whereMonth('month_year', $monthNumber)
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

    /**
     * Accept YYYY-MM or a valid YYYY-MM-DD and return YYYY-MM-01. Invalid
     * values are left untouched so they fail normal validation instead of
     * being silently rewritten to a different month.
     */
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
