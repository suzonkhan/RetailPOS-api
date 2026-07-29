<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ChangePinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_pin' => ['required', 'digits:6'],
            'new_pin' => ['required', 'digits:6', 'different:current_pin'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_pin.digits' => 'Current PIN must be exactly 6 digits.',
            'new_pin.digits' => 'New PIN must be exactly 6 digits.',
            'new_pin.different' => 'New PIN must be different from the current PIN.',
        ];
    }
}
