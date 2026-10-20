<?php

namespace App\Http\Requests;

use App\Models\Income;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept legacy human labels ("Monthly") and normalize them to the
        // contract slugs ("monthly") so older clients keep working.
        if ($this->has('frequency') && is_string($this->input('frequency'))) {
            $this->merge([
                'frequency' => str_replace(' ', '-', strtolower(trim($this->input('frequency')))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'amount'      => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'source'      => 'required|string|max:255',
            'income_date' => 'required|date',
            'frequency'   => ['nullable', 'string', Rule::in(Income::FREQUENCIES)],
            'notes'       => 'nullable|string|max:1000',
        ];
    }
}
