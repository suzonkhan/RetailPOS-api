<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('staff.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'pay_type' => ['sometimes', 'string', Rule::in(['daily', 'weekly', 'monthly', 'other'])],
            'agreed_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rate_unit' => ['sometimes', 'nullable', 'string', Rule::in(['per_day', 'per_month'])],
            'joined_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
