<?php

namespace App\Http\Requests\Checkout;

use App\Models\Store;
use App\Models\SubscriptionInvoice;
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
        $intent = $this->input('intent', SubscriptionInvoice::INTENT_RENEW);

        return [
            'intent' => ['required', Rule::in([
                SubscriptionInvoice::INTENT_CREATE_BRANCH,
                SubscriptionInvoice::INTENT_RENEW,
                SubscriptionInvoice::INTENT_UPGRADE,
            ])],
            'store_id' => [
                Rule::requiredIf(fn () => $intent !== SubscriptionInvoice::INTENT_CREATE_BRANCH),
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id', 'required_without:plan_slug'],
            'plan_slug' => ['nullable', 'string', 'required_without:plan_id'],
            'billing_cycle' => ['required', Rule::in([
                Tenant::BILLING_MONTHLY,
                Tenant::BILLING_YEARLY,
                Store::BILLING_MONTHLY,
                Store::BILLING_YEARLY,
            ])],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'branch_meta' => [Rule::requiredIf(fn () => $intent === SubscriptionInvoice::INTENT_CREATE_BRANCH), 'nullable', 'array'],
            'branch_meta.name' => [Rule::requiredIf(fn () => $intent === SubscriptionInvoice::INTENT_CREATE_BRANCH), 'string', 'max:255'],
            'branch_meta.phone' => ['nullable', 'string', 'max:20'],
            'branch_meta.address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
