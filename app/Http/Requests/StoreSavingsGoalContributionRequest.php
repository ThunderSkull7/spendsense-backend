<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavingsGoalContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'         => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'note'           => 'nullable|string|max:500',
            'contributed_at' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ];
    }
}
