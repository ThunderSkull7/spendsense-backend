<?php

namespace App\Http\Requests;

use App\Models\NetWorthEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNetWorthEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:120',
            'type' => ['sometimes', 'required', Rule::in(NetWorthEntry::TYPES)],
            'category' => ['sometimes', 'required', Rule::in(NetWorthEntry::CATEGORIES)],
            'institution' => 'sometimes|nullable|string|max:120',
            'notes' => 'sometimes|nullable|string|max:2000',
        ];
    }
}
