<?php

namespace App\Http\Requests;

use App\Models\RecurringIncome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecurringIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|filled|string|max:255',
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'frequency' => ['sometimes', 'string', Rule::in(RecurringIncome::FREQUENCIES)],
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d',
            'notes' => 'sometimes|nullable|string|max:1000',
        ];
    }
}
