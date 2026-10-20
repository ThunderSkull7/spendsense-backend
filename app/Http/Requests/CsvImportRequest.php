<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class CsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], // 2 MB
            'confirm' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a CSV file to import.',
            'file.mimes' => 'The file must be a CSV file.',
            'file.max' => 'The file must not exceed 2 MB.',
            'confirm.accepted' => 'You must confirm the import before proceeding.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'confirm' => $this->boolean('confirm'),
        ]);
    }
}