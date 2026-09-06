<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\CustomerDue;
use App\Models\DuePayment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DuePaymentService
{
    public function __construct(
        private readonly SalesScopeService $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordForCustomer(User $user, Customer $customer, array $data): DuePayment
    {
        $this->scope->authorizeCustomer($user, $customer);

        try {
            return DB::transaction(function () use ($user, $customer, $data) {
                if (! empty($data['uuid'])) {
                    $existing = DuePayment::query()
                        ->where('tenant_id', $user->tenant_id)
                        ->where('uuid', $data['uuid'])
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $existing->load(['customerDue', 'paymentMethod']);
                    }
                }

                $store = $this->scope->resolveStore($user);
                $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Amount must be greater than zero.'],
                ]);
            }

            $paymentMethodId = $data['payment_method_id'] ?? null;

            if ($paymentMethodId !== null) {
                $method = PaymentMethod::query()
                    ->where('store_id', $store->id)
                    ->where('id', $paymentMethodId)
                    ->first();

                if ($method === null) {
                    throw ValidationException::withMessages([
                        'payment_method_id' => ['Invalid payment method for this store.'],
                    ]);
                }

                if (! $method->is_active) {
                    throw ValidationException::withMessages([
                        'payment_method_id' => ['Inactive payment methods cannot be used to collect dues.'],
                    ]);
                }

                if ($method->is_credit) {
                    throw ValidationException::withMessages([
                        'payment_method_id' => ['Credit/due payment methods cannot be used to collect dues.'],
                    ]);
                }
            }

            $remaining = $amount;
            $firstDuePayment = null;
            $clientUuid = $data['uuid'] ?? null;

            if (! empty($data['customer_due_id'])) {
                $due = CustomerDue::query()
                    ->where('customer_id', $customer->id)
                    ->where('id', $data['customer_due_id'])
                    ->lockForUpdate()
                    ->first();

                if ($due === null || (int) $due->customer_id !== (int) $customer->id || $due->status !== CustomerDue::STATUS_OPEN) {
                    throw ValidationException::withMessages([
                        'customer_due_id' => ['Invalid or already settled due record.'],
                    ]);
                }

                $firstDuePayment = $this->applyToDue(
                    $user,
                    $customer,
                    $due,
                    $remaining,
                    $paymentMethodId,
                    $data['reference'] ?? null,
                    $clientUuid,
                );
                $remaining = 0;
            } else {
                $openDues = CustomerDue::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', CustomerDue::STATUS_OPEN)
                    ->where('balance', '>', 0)
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->get();

                $openBalance = (float) $openDues->sum('balance');

                if ($amount > $openBalance + 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => ["Amount exceeds open due balance ({$openBalance})."],
                    ]);
                }

                foreach ($openDues as $due) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $applyAmount = min($remaining, (float) $due->balance);
                    $payment = $this->applyToDue(
                        $user,
                        $customer,
                        $due,
                        $applyAmount,
                        $paymentMethodId,
                        $data['reference'] ?? null,
                        $clientUuid,
                    );
                    $clientUuid = null;
                    $firstDuePayment ??= $payment;
                    $remaining -= $applyAmount;
                }
            }

            if ($firstDuePayment === null) {
                throw ValidationException::withMessages([
                    'amount' => ['No open due balance to settle.'],
                ]);
            }

            return $firstDuePayment->load(['customerDue', 'paymentMethod']);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! empty($data['uuid'])) {
                $existing = DuePayment::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('uuid', $data['uuid'])
                    ->first();

                if ($existing !== null) {
                    return $existing->load(['customerDue', 'paymentMethod']);
                }
            }

            throw $e;
        }
    }

    private function applyToDue(
        User $user,
        Customer $customer,
        CustomerDue $due,
        float $amount,
        ?int $paymentMethodId,
        ?string $reference,
        ?string $uuid = null,
    ): DuePayment {
        $applyAmount = round(min($amount, (float) $due->balance), 2);

        $duePayment = DuePayment::query()->create([
            'uuid' => $uuid,
            'tenant_id' => $user->tenant_id,
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'customer_due_id' => $due->id,
            'payment_method_id' => $paymentMethodId,
            'user_id' => $user->id,
            'amount' => $applyAmount,
            'reference' => $reference,
        ]);

        $newBalance = round((float) $due->balance - $applyAmount, 2);
        $due->balance = $newBalance;

        if ($newBalance <= 0) {
            $due->balance = 0;
            $due->status = CustomerDue::STATUS_SETTLED;
        }

        $due->save();

        return $duePayment;
    }
}
