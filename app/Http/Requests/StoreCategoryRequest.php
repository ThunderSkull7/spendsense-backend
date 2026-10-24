<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|in:income,expense',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $name = (string) $this->input('name');
                $type = (string) $this->input('type');
                $userId = (int) $this->user()->id;

                if ($this->nameExistsForUser($name, $type, $userId)) {
                    $validator->errors()->add(
                        'name',
                        'You already have a category with this name and type.'
                    );

                    return;
                }

                if ($this->nameExistsAsDefault($name, $type)) {
                    $validator->errors()->add(
                        'name',
                        'This name and type already exist as a shared default category.'
                    );
                }
            },
        ];
    }

    private function nameExistsForUser(string $name, string $type, int $userId): bool
    {
        return Category::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    private function nameExistsAsDefault(string $name, string $type): bool
    {
        return Category::query()
            ->whereNull('user_id')
            ->where('is_default', true)
            ->where('type', $type)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
    }
}
