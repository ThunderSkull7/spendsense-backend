<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'      => 'required|numeric|min:0',
            'source'      => 'required|string|max:255',
            'income_date' => 'required|date',
        ];
    }
}
