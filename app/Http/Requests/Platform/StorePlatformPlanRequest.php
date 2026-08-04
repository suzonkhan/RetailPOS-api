<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlatformPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.plans') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('plans', 'slug')],
            'monthly_price' => ['required', 'integer', 'min:0'],
            'yearly_price' => ['nullable', 'integer', 'min:0'],
            'max_users' => ['required', 'integer', 'min:1'],
            'max_stores' => ['nullable', 'integer', 'min:1'],
            'max_categories' => ['required', 'integer', 'min:1'],
            'max_products' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'is_trial_default' => ['sometimes', 'boolean'],
        ];
    }
}
