<?php

namespace App\Http\Requests\Auth;

use App\Support\MobileNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'mobile' => ['required', 'regex:/^8801\d{9}$/'],
            'pin' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Mobile must be a valid Bangladesh number (8801XXXXXXXXX).',
            'pin.digits' => 'PIN must be exactly 6 digits.',
        ];
    }
}
