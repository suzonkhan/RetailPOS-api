<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class ResetOwnerPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.digits' => 'PIN must be exactly 6 digits.',
        ];
    }
}
