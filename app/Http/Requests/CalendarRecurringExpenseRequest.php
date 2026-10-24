<?php

namespace App\Http\Requests;

use App\Rules\AvailableCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarRecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['sometimes', Rule::in(['planned', 'unpaid', 'overdue', 'due', 'paid', 'skipped', 'cancelled'])],
            'category_id' => [
                'sometimes',
                'integer',
                new AvailableCategory((int) $this->user()->id),
            ],
        ];
    }
}
