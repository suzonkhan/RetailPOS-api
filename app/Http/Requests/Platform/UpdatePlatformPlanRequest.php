<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePlatformPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.plans') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'monthly_price' => ['sometimes', 'integer', 'min:0'],
            'yearly_price' => ['nullable', 'integer', 'min:0'],
            'max_users' => ['sometimes', 'integer', 'min:1'],
            'max_stores' => ['sometimes', 'integer', 'min:1'],
            'max_categories' => ['sometimes', 'integer', 'min:1'],
            'max_products' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'is_trial_default' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny([
                'name',
                'monthly_price',
                'yearly_price',
                'max_users',
                'max_stores',
                'max_categories',
                'max_products',
                'is_active',
                'is_trial_default',
            ])) {
                $validator->errors()->add('body', 'At least one field is required.');
            }
        });
    }
}
