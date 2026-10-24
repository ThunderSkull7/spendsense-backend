<?php

namespace App\Http\Requests;

use App\Models\RecurringExpense;
use App\Rules\AvailableCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scheduleId = $this->route('recurringExpense');
        $currentCategoryId = is_numeric($scheduleId)
            ? RecurringExpense::query()
                ->where('user_id', $this->user()->id)
                ->whereKey((int) $scheduleId)
                ->value('category_id')
            : null;

        return [
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'category_id' => [
                'sometimes',
                'integer',
                new AvailableCategory(
                    (int) $this->user()->id,
                    $currentCategoryId === null ? null : (int) $currentCategoryId
                ),
            ],
            'frequency' => ['sometimes', Rule::in(RecurringExpense::FREQUENCIES)],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'reminder_days_before' => ['sometimes', 'integer', 'between:0,30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $scheduleId = $this->route('recurringExpense');
                $schedule = is_numeric($scheduleId)
                    ? RecurringExpense::query()->find((int) $scheduleId)
                    : null;
                $start = $this->input('start_date', $schedule?->start_date?->toDateString());
                $end = $this->exists('end_date')
                    ? $this->input('end_date')
                    : $schedule?->end_date?->toDateString();

                if ($end === null || $end === '') {
                    return;
                }

                if (is_string($start) && is_string($end) && $end < $start) {
                    $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
                }
            },
        ];
    }
}
