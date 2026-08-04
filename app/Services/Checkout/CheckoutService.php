<?php

namespace App\Services\Checkout;

use App\Contracts\BkashGateway;
use App\Models\BkashPayment;
use App\Models\Plan;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Branch\BranchScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly CheckoutQuoteService $quoteService,
        private readonly CouponService $couponService,
        private readonly BkashGateway $bkashGateway,
        private readonly SubscriptionActivationService $activationService,
        private readonly BranchScopeService $branchScope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createPayment(User $user, array $data): array
    {
        $tenant = $user->tenant;
        $intent = $data['intent'] ?? SubscriptionInvoice::INTENT_RENEW;
        $store = $this->resolveStoreForCheckout($user, $data, $intent);
        $quote = $this->quoteService->quote($tenant, $data, $store);
        $plan = Plan::query()->findOrFail($quote['plan']['id']);
        $coupon = $this->couponService->findValidCoupon($data['coupon_code'] ?? null, $plan);

        return DB::transaction(function () use ($tenant, $store, $plan, $quote, $coupon, $data, $intent) {
            $invoice = SubscriptionInvoice::query()->create([
                'tenant_id' => $tenant->id,
                'store_id' => $store?->id,
                'plan_id' => $plan->id,
                'coupon_id' => $coupon?->id,
                'intent' => $intent,
                'branch_meta' => $intent === SubscriptionInvoice::INTENT_CREATE_BRANCH
                    ? ($data['branch_meta'] ?? null)
                    : null,
                'billing_cycle' => $data['billing_cycle'],
                'subtotal' => $quote['subtotal'],
                'discount_amount' => $quote['discount_amount'],
                'total_amount' => $quote['total'],
                'status' => SubscriptionInvoice::STATUS_PENDING,
            ]);

            $callbackUrl = config('retail360.bkash.callback_url')
                ?: rtrim(config('app.url'), '/').'/api/v1/checkout/bkash/callback';

            $response = $this->bkashGateway->createPayment(
                (string) $invoice->total_amount,
                (string) $invoice->id,
                $callbackUrl,
            );

            $payment = BkashPayment::query()->create([
                'subscription_invoice_id' => $invoice->id,
                'payment_id' => $response['paymentID'] ?? null,
                'amount' => $invoice->total_amount,
                'status' => BkashPayment::STATUS_CREATED,
                'create_response' => $response,
            ]);

            return [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->payment_id,
                'intent' => $intent,
                'bkashURL' => $response['bkashURL'],
                'amount' => $invoice->total_amount,
                'currency' => 'BDT',
                'gateway' => $this->bkashGateway->isMock() ? 'mock' : 'bkash',
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function executePayment(User $user, string $paymentId): array
    {
        $payment = BkashPayment::query()
            ->where('payment_id', $paymentId)
            ->whereHas('subscriptionInvoice', fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->with('subscriptionInvoice.store')
            ->first();

        if ($payment === null) {
            throw ValidationException::withMessages([
                'payment_id' => ['Payment not found.'],
            ]);
        }

        if ($payment->status === BkashPayment::STATUS_COMPLETED) {
            return $this->successPayload($payment);
        }

        $response = $this->bkashGateway->executePayment($paymentId);

        $payment->update([
            'execute_response' => $response,
            'trx_id' => $response['trxID'] ?? null,
            'transaction_status' => $response['transactionStatus'] ?? null,
        ]);

        if (($response['statusCode'] ?? '') !== '0000') {
            $payment->update(['status' => BkashPayment::STATUS_FAILED]);

            throw ValidationException::withMessages([
                'payment_id' => [$response['statusMessage'] ?? 'Payment execution failed.'],
            ]);
        }

        $store = $this->activationService->activateFromInvoice($payment->subscriptionInvoice, $payment);

        return $this->successPayload($payment->fresh(), $store);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): array
    {
        $paymentId = $payload['paymentID'] ?? $payload['payment_id'] ?? null;

        if (! is_string($paymentId) || $paymentId === '') {
            return ['status' => 'ignored', 'message' => 'Missing payment ID'];
        }

        $payment = BkashPayment::query()
            ->where('payment_id', $paymentId)
            ->with('subscriptionInvoice')
            ->first();

        if ($payment === null) {
            return ['status' => 'ignored', 'message' => 'Payment not found'];
        }

        if ($payment->status === BkashPayment::STATUS_COMPLETED) {
            return ['status' => 'already_processed'];
        }

        $payment->update(['webhook_payload' => $payload]);

        $trxStatus = $payload['transactionStatus'] ?? $payload['trxStatus'] ?? null;
        $statusCode = $payload['statusCode'] ?? null;

        $isSuccess = $trxStatus === 'Completed'
            || $statusCode === '0000'
            || ($payload['status'] ?? null) === 'success';

        if (! $isSuccess) {
            $payment->update(['status' => BkashPayment::STATUS_FAILED]);

            return ['status' => 'failed'];
        }

        $this->activationService->activateFromInvoice($payment->subscriptionInvoice, $payment);

        return ['status' => 'processed'];
    }

    private function resolveStoreForCheckout(User $user, array $data, string $intent): ?Store
    {
        if ($intent === SubscriptionInvoice::INTENT_CREATE_BRANCH) {
            return null;
        }

        $storeId = $data['store_id'] ?? null;

        if ($storeId === null) {
            throw ValidationException::withMessages([
                'store_id' => ['Branch is required for this checkout.'],
            ]);
        }

        return $this->branchScope->resolveBranch($user, (int) $storeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(BkashPayment $payment, ?Store $store = null): array
    {
        $store ??= $payment->subscriptionInvoice->store?->fresh(['plan']);

        return [
            'payment_id' => $payment->payment_id,
            'status' => 'completed',
            'intent' => $payment->subscriptionInvoice->intent,
            'transaction_status' => $payment->transaction_status,
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
            ] : null,
            'subscription' => $store ? [
                'status' => $store->status,
                'is_trial' => $store->isOnTrial(),
                'subscribed_at' => $store->subscribed_at?->toIso8601String(),
                'current_period_ends_at' => $store->current_period_ends_at?->toIso8601String(),
                'billing_cycle' => $store->billing_cycle,
                'plan' => $store->plan ? [
                    'id' => $store->plan->id,
                    'slug' => $store->plan->slug,
                    'name' => $store->plan->name,
                ] : null,
            ] : null,
        ];
    }
}
