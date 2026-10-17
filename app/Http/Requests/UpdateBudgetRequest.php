<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id'  => 'sometimes|integer|exists:categories,id',
            'limit_amount' => 'sometimes|numeric|min:0',
            'month_year'   => 'sometimes|date',
        ];
    }
}
