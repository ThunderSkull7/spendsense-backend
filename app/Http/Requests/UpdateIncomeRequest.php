<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'      => 'sometimes|numeric|min:0',
            'source'      => 'sometimes|string|max:255',
            'income_date' => 'sometimes|date',
        ];
    }
}
