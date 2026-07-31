<?php

namespace App\Http\Requests\Platform;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePlatformCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.coupons') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge([
                'code' => strtoupper(trim((string) $this->input('code'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('coupons', 'code')],
            'type' => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            'value' => ['required', 'integer', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'applicable_plans' => ['nullable', 'array'],
            'applicable_plans.*' => ['string', Rule::exists('plans', 'slug')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') === Coupon::TYPE_PERCENT && (int) $this->input('value') > 100) {
                $validator->errors()->add('value', 'Percent discount cannot exceed 100.');
            }
        });
    }
}
