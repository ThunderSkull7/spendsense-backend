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
            'amount'       => 'sometimes|numeric|min:0',
            'category_id'  => 'sometimes|integer|exists:categories,id',
            'expense_date' => 'sometimes|date',
            'description'  => 'nullable|string',
        ];
    }
}
