<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        if (! $category instanceof Category) {
            return false;
        }

        if ($category->isDefault()) {
            return false; // 403: shared defaults are never editable.
        }

        if ($category->user_id !== $this->user()->id) {
            abort(404); // Private categories belonging to someone else are invisible.
        }

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
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:income,expense',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $category = $this->route('category');
                if (! $category instanceof Category) {
                    return;
                }

                $name = (string) $this->input('name', $category->name);
                $type = (string) $this->input('type', $category->type);
                $userId = (int) $this->user()->id;

                if ($this->nameExistsForUser($name, $type, $userId, $category->id)) {
                    $validator->errors()->add(
                        'name',
                        'You already have a category with this name and type.'
                    );

                    return;
                }

                if ($this->nameExistsAsDefault($name, $type, $category->id)) {
                    $validator->errors()->add(
                        'name',
                        'This name and type already exist as a shared default category.'
                    );
                }
            },
        ];
    }

    private function nameExistsForUser(string $name, string $type, int $userId, int $ignoreId): bool
    {
        return Category::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->whereKeyNot($ignoreId)
            ->exists();
    }

    private function nameExistsAsDefault(string $name, string $type, int $ignoreId): bool
    {
        return Category::query()
            ->whereNull('user_id')
            ->where('is_default', true)
            ->where('type', $type)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->whereKeyNot($ignoreId)
            ->exists();
    }
}
