<?php

namespace App\Services\Platform;

use App\Models\BkashPayment;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Checkout\SubscriptionActivationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class PlatformTenantBillingService
{
    public function __construct(
        private readonly SubscriptionActivationService $activationService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $query = SubscriptionInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->with(['plan', 'store', 'bkashPayments' => fn ($q) => $q->orderByDesc('id')])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (($filters['payment_status'] ?? '') === 'failed') {
            $query->whereHas('bkashPayments', fn ($q) => $q->where('status', BkashPayment::STATUS_FAILED));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    public function approve(Tenant $tenant, SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->tenant_id !== $tenant->id) {
            abort(404);
        }

        if (! $this->canApprove($invoice)) {
            throw ValidationException::withMessages([
                'invoice' => ['This invoice cannot be approved. Only pending invoices with failed payments can be approved.'],
            ]);
        }

        $this->activationService->activateFromInvoice($invoice);

        return $invoice->fresh(['plan', 'store', 'bkashPayments']);
    }

    public function canApprove(SubscriptionInvoice $invoice): bool
    {
        if ($invoice->status !== SubscriptionInvoice::STATUS_PENDING) {
            return false;
        }

        if ($invoice->bkashPayments()->where('status', BkashPayment::STATUS_COMPLETED)->exists()) {
            return false;
        }

        return $invoice->bkashPayments()
            ->where('status', BkashPayment::STATUS_FAILED)
            ->exists();
    }
}
