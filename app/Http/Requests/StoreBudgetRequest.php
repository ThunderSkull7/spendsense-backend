<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id'  => 'required|integer|exists:categories,id',
            'limit_amount' => 'required|numeric|min:0',
            'month_year'   => 'required|date',
        ];
    }
}
