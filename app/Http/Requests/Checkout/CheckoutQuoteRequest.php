<?php

namespace App\Http\Requests\Checkout;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['nullable', 'integer', 'exists:plans,id', 'required_without:plan_slug'],
            'plan_slug' => ['nullable', 'string', 'required_without:plan_id'],
            'billing_cycle' => ['required', Rule::in([Tenant::BILLING_MONTHLY, Tenant::BILLING_YEARLY])],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
