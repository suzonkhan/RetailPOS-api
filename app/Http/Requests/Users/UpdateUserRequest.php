<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'pin' => ['sometimes', 'required', 'digits:6'],
            'role' => ['sometimes', 'required', 'string', Rule::in(config('retail360.tenant_roles'))],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.digits' => 'PIN must be exactly 6 digits.',
        ];
    }
}
