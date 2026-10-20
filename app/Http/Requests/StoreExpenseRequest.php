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
            'amount'       => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'category_id'  => 'required|integer|exists:categories,id',
            'expense_date' => 'required|date',
            'description'  => 'nullable|string',
        ];
    }
}
