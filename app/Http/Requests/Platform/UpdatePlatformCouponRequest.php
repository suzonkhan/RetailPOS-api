<?php

namespace App\Http\Requests\Platform;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePlatformCouponRequest extends FormRequest
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
        /** @var Coupon $coupon */
        $coupon = $this->route('coupon');

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('coupons', 'code')->ignore($coupon->id)],
            'type' => ['sometimes', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            'value' => ['sometimes', 'integer', 'min:1'],
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
            if (! $this->hasAny([
                'code',
                'type',
                'value',
                'max_uses',
                'valid_from',
                'valid_to',
                'applicable_plans',
                'is_active',
            ])) {
                $validator->errors()->add('body', 'At least one field is required.');
            }

            $type = $this->input('type', $this->route('coupon')->type);
            $value = $this->input('value', $this->route('coupon')->value);

            if ($type === Coupon::TYPE_PERCENT && (int) $value > 100) {
                $validator->errors()->add('value', 'Percent discount cannot exceed 100.');
            }
        });
    }
}
