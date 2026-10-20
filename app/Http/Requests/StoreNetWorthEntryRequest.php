<?php

namespace App\Http\Requests;

use App\Models\NetWorthEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreNetWorthEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'type' => ['required', Rule::in(NetWorthEntry::TYPES)],
            'category' => ['required', Rule::in(NetWorthEntry::CATEGORIES)],
            'institution' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000',
            'value' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'valuation_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'valuation_note' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = (string) $this->input('type');
            $category = (string) $this->input('category');

            if (in_array($type, NetWorthEntry::TYPES, true)
                && ! in_array($category, NetWorthEntry::categoriesFor($type), true)) {
                $validator->errors()->add(
                    'category',
                    'The selected category does not belong to this entry type.'
                );
            }
        });
    }
}
