<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use App\Rules\AvailableCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $subscription = $this->route('subscription');
        $linked = $subscription instanceof Subscription && $subscription->isLinked();

        $rules = [
            'name' => 'sometimes|required|string|max:120',
            'provider' => 'sometimes|nullable|string|max:120',
            'status' => ['sometimes', 'required', Rule::in(Subscription::STATUSES)],
            'notes' => 'sometimes|nullable|string|max:2000',
        ];

        if ($linked) {
            // Linkage and schedule-derived values are immutable. A tracker can
            // be cancelled and a new one created if it was linked incorrectly.
            $rules['recurring_expense_id'] = 'prohibited';
            $rules['amount'] = 'prohibited';
            $rules['frequency'] = 'prohibited';
            $rules['next_renewal_date'] = 'prohibited';
            $rules['category_id'] = 'prohibited';
        } else {
            $rules['recurring_expense_id'] = 'prohibited';
            $rules['amount'] = ['sometimes', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'];
            $rules['frequency'] = ['sometimes', Rule::in(Subscription::FREQUENCIES)];
            $rules['next_renewal_date'] = 'sometimes|date_format:Y-m-d';
            $rules['category_id'] = [
                'sometimes',
                'nullable',
                'integer',
                new AvailableCategory(
                    (int) $this->user()->id,
                    $subscription instanceof Subscription && $subscription->category_id !== null
                        ? (int) $subscription->category_id
                        : null
                ),
            ];
        }

        return $rules;
    }
}
