<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class NotificationFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'in:all,unread,read'],
            'month' => ['nullable', 'date_format:Y-m'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'filter.in' => 'Filter must be one of: all, unread, read.',
            'month.date_format' => 'Use YYYY-MM format for month.',
            'limit.between' => 'Limit must be between 1 and 200.',
            'offset.min' => 'Offset must be a non-negative integer.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'filter' => $this->input('filter') ?? 'all',
            'limit' => $this->input('limit') ?? 100,
            'offset' => $this->input('offset') ?? 0,
        ]);
    }
}