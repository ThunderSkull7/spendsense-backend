<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNetWorthValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'valuation_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'note' => 'nullable|string|max:1000',
        ];
    }
}
