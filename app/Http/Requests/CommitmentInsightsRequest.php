<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommitmentInsightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
