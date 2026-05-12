<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',

            'amount' => 'required|numeric|min:0',

            'category_id' => 'required|exists:categories,id',

            'expense_date' => 'required|date',

            'description' => 'nullable|string|max:500',
        ];
    }
}