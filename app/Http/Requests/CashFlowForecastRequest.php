<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashFlowForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'horizon' => 'nullable|integer|in:30,60,90',
            'as_of' => 'nullable|date_format:Y-m-d',
        ];
    }
}
