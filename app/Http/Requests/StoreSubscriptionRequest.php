<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'provider' => 'nullable|string|max:120',
            'recurring_expense_id' => [
                'nullable',
                'integer',
                Rule::exists('recurring_expenses', 'id')->where(
                    fn ($query) => $query->where('user_id', auth()->id())
                ),
            ],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'frequency' => ['nullable', Rule::in(Subscription::FREQUENCIES)],
            'next_renewal_date' => 'nullable|date_format:Y-m-d',
            'category_id' => 'nullable|integer|exists:categories,id',
            'status' => ['sometimes', 'required', Rule::in(Subscription::STATUSES)],
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $linked = $this->filled('recurring_expense_id');

            if ($linked) {
                foreach (['amount', 'frequency', 'next_renewal_date', 'category_id'] as $field) {
                    if ($this->filled($field)) {
                        $validator->errors()->add(
                            $field,
                            'A linked subscription uses the recurring expense schedule for this value.'
                        );
                    }
                }

                return;
            }

            foreach (['amount' => 'amount', 'frequency' => 'billing frequency', 'next_renewal_date' => 'next renewal date'] as $field => $label) {
                if (! $this->filled($field)) {
                    $validator->errors()->add($field, "The {$label} is required when no recurring expense is linked.");
                }
            }
        });
    }
}
