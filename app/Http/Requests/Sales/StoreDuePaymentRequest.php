<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDuePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.use') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'customer_due_id' => ['nullable', 'integer', Rule::exists('customer_dues', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
