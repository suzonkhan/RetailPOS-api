<?php

namespace App\Http\Requests\Auth;

use App\Support\MobileNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'shop_name' => ['nullable', 'string', 'max:255'],
            'store_name' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'mobile' => [
                'required',
                'regex:/^8801\d{9}$/',
                Rule::unique('users', 'mobile'),
            ],
            'pin' => ['required', 'digits:6'],
            'plan_slug' => ['prohibited'],
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
