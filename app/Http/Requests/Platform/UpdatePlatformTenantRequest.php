<?php

namespace App\Http\Requests\Platform;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePlatformTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('platform.tenants') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([Tenant::STATUS_SUSPENDED, Tenant::STATUS_ACTIVE])],
            'plan_slug' => [
                'sometimes',
                'string',
                Rule::exists('plans', 'slug')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'trial_ends_at' => ['sometimes', 'date', 'after:now'],
            'extend_trial_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny(['status', 'plan_slug', 'trial_ends_at', 'extend_trial_days'])) {
                $validator->errors()->add('body', 'At least one field is required.');
            }

            if ($this->has('trial_ends_at') && $this->has('extend_trial_days')) {
                $validator->errors()->add('body', 'Use either trial_ends_at or extend_trial_days, not both.');
            }
        });
    }
}
