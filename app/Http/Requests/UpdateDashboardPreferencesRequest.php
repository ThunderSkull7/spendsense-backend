<?php

namespace App\Http\Requests;

use App\Support\DashboardWidgets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'widgets' => ['required', 'array', 'min:1', 'max:'.count(DashboardWidgets::KEYS)],
            'widgets.*' => ['required', 'array:key,visible'],
            'widgets.*.key' => [
                'required',
                'string',
                'distinct:strict',
                Rule::in(DashboardWidgets::KEYS),
            ],
            'widgets.*.visible' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'widgets.*.key.distinct' => 'Each dashboard widget may appear only once.',
            'widgets.*.key.in' => 'One of the dashboard widgets is not supported.',
        ];
    }
}
