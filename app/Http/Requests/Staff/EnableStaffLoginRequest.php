<?php

namespace App\Http\Requests\Staff;

use App\Support\MobileNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnableStaffLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('mobile')) {
            $this->merge([
                'mobile' => MobileNormalizer::normalize((string) $this->input('mobile')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'mobile' => [
                'required',
                'regex:/^8801\d{9}$/',
                Rule::unique('users', 'mobile'),
            ],
            'pin' => ['required', 'digits:6'],
            'role' => ['required', 'string', Rule::in(config('retail360.tenant_roles'))],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Mobile must be a valid Bangladesh number (8801XXXXXXXXX).',
            'mobile.unique' => 'This mobile number is already registered.',
            'pin.digits' => 'PIN must be exactly 6 digits.',
        ];
    }
}
