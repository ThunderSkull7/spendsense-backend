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
            'title' => 'sometimes|required|string|max:255',

            'amount' => 'sometimes|required|numeric|min:0',

            'category_id' => 'sometimes|required|exists:categories,id',

            'expense_date' => 'sometimes|required|date',

            'description' => 'nullable|string|max:500',
        ];
    }
}