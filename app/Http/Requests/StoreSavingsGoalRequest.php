<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:120',
            'description'   => 'nullable|string|max:2000',
            'target_amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'target_date'   => 'nullable|date_format:Y-m-d',
            'notes'         => 'nullable|string|max:2000',
        ];
    }
}
