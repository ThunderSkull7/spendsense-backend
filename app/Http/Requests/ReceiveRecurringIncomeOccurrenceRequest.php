<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveRecurringIncomeOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual_amount' => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'actual_received_date' => 'sometimes|date_format:Y-m-d',
        ];
    }
}
