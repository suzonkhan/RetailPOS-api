<?php

namespace App\Http\Requests\Sync;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.use') ?? false;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
