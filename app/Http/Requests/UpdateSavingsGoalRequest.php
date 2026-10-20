<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSavingsGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => 'sometimes|required|string|max:120',
            'description'   => 'sometimes|nullable|string|max:2000',
            'target_amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'target_date'   => 'sometimes|nullable|date_format:Y-m-d',
            'notes'         => 'sometimes|nullable|string|max:2000',
        ];
    }
}
