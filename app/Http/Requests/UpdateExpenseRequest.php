<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'       => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'category_id'  => 'sometimes|integer|exists:categories,id',
            'expense_date' => 'sometimes|date',
            'description'  => 'nullable|string',
        ];
    }
}
