<?php

namespace App\Http\Requests;

use App\Models\RecurringExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'frequency' => ['required', Rule::in(RecurringExpense::FREQUENCIES)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reminder_days_before' => ['sometimes', 'integer', 'between:0,30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
