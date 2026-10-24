<?php

namespace App\Http\Requests;

use App\Rules\AvailableCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TransactionSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'in:all,expense,income'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'category_id' => [
                'nullable',
                'integer',
                new AvailableCategory((int) $this->user()->id),
            ],
            'sort' => ['nullable', 'in:date_desc,date_asc,amount_desc,amount_asc'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if ($from && $to && $to < $from) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');
            }

            if ($from && $to) {
                $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));

                if ($days > 3653) {
                    $validator->errors()->add('to', 'Transaction searches are limited to ten years at a time.');
                }
            }

            if ($this->input('min_amount') !== null
                && $this->input('max_amount') !== null
                && (float) $this->input('min_amount') > (float) $this->input('max_amount')) {
                $validator->errors()->add('max_amount', 'The maximum amount must be greater than or equal to the minimum amount.');
            }

            if ($this->input('category_id') !== null && $this->input('type') === 'income') {
                $validator->errors()->add('category_id', 'Category filtering is available for expenses; income is grouped by source.');
            }
        });
    }
}
