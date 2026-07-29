<?php

namespace App\Http\Requests\Sync;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.use') ?? false;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'entities' => ['required', 'array'],
            'entities.sales' => ['sometimes', 'array'],
            'entities.sales.*.client_uuid' => ['required', 'uuid'],
            'entities.sales.*.customer_id' => ['sometimes', 'nullable', 'integer'],
            'entities.sales.*.customer_uuid' => ['sometimes', 'nullable', 'uuid'],
            'entities.sales.*.items' => ['required', 'array', 'min:1'],
            'entities.sales.*.items.*.product_id' => ['sometimes', 'integer'],
            'entities.sales.*.items.*.product_uuid' => ['sometimes', 'uuid'],
            'entities.sales.*.items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'entities.sales.*.items.*.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'entities.sales.*.payments' => ['required', 'array', 'min:1'],
            'entities.sales.*.payments.*.payment_method_id' => ['sometimes', 'integer'],
            'entities.sales.*.payments.*.payment_method_uuid' => ['sometimes', 'uuid'],
            'entities.sales.*.payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'entities.sales.*.payments.*.reference' => ['nullable', 'string', 'max:255'],
            'entities.customers' => ['sometimes', 'array'],
            'entities.customers.*.uuid' => ['required', 'uuid'],
            'entities.customers.*.name' => ['required', 'string', 'max:255'],
            'entities.customers.*.mobile' => ['nullable', 'string', 'max:20'],
            'entities.customers.*.email' => ['nullable', 'email', 'max:255'],
            'entities.customers.*.address' => ['nullable', 'string'],
            'entities.customers.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('entities.sales', []) as $saleIndex => $sale) {
                foreach ($sale['items'] ?? [] as $itemIndex => $item) {
                    if (empty($item['product_id']) && empty($item['product_uuid'])) {
                        $validator->errors()->add(
                            "entities.sales.{$saleIndex}.items.{$itemIndex}.product_uuid",
                            'Either product_id or product_uuid is required.'
                        );
                    }
                }

                foreach ($sale['payments'] ?? [] as $paymentIndex => $payment) {
                    if (empty($payment['payment_method_id']) && empty($payment['payment_method_uuid'])) {
                        $validator->errors()->add(
                            "entities.sales.{$saleIndex}.payments.{$paymentIndex}.payment_method_uuid",
                            'Either payment_method_id or payment_method_uuid is required.'
                        );
                    }
                }
            }
        });
    }
}
