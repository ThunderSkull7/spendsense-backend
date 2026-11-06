<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $rules = [
            'file' => ['required', 'file', 'max:2048'], // 2 MB limit
            'duplicate_policy' => ['nullable', 'string', 'in:skip,import'],
            'mapping' => ['nullable'],
        ];

        // For final commit/import endpoint, require explicit confirmation
        if ($this->routeIs('*.import') || $this->is('*/import') || $this->boolean('confirm')) {
            $rules['confirm'] = ['required', 'accepted'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a CSV file to import.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file must not exceed 2 MB.',
            'confirm.required' => 'You must confirm the import before proceeding.',
            'confirm.accepted' => 'You must confirm the import before proceeding.',
            'duplicate_policy.in' => 'Duplicate policy must be either "skip" or "import".',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('confirm')) {
            $this->merge([
                'confirm' => $this->boolean('confirm'),
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $file = $this->file('file');
            if ($file && $file->isValid()) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (!in_array($ext, ['csv', 'txt'], true)) {
                    $validator->errors()->add('file', 'The file must be a CSV file with a .csv or .txt extension.');
                }
            }
        });
    }
}
