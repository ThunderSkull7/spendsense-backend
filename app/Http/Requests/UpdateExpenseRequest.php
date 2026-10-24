<?php

namespace App\Http\Requests;

use App\Models\Expense;
use App\Rules\AvailableCategory;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $expense = $this->route('expense');
        $currentCategoryId = $expense instanceof Expense
            ? (int) $expense->category_id
            : null;

        return [
            'amount'       => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'category_id'  => [
                'sometimes',
                'integer',
                new AvailableCategory(
                    (int) $this->user()->id,
                    $currentCategoryId
                ),
            ],
            'expense_date' => 'sometimes|date',
            'description'  => 'nullable|string',
        ];
    }
}
