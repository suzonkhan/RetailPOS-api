<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformTenantBillingResource;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Services\Platform\PlatformTenantBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformTenantBillingController extends Controller
{
    public function __construct(
        private readonly PlatformTenantBillingService $billing,
    ) {}

    public function index(Tenant $tenant): AnonymousResourceCollection
    {
        $paginator = $this->billing->list(
            $tenant,
            request()->only(['payment_status', 'page', 'per_page']),
        );

        return PlatformTenantBillingResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function approve(Tenant $tenant, SubscriptionInvoice $invoice): JsonResponse
    {
        $invoice = $this->billing->approve($tenant, $invoice);

        return PlatformTenantBillingResource::make($invoice)
            ->response()
            ->setStatusCode(200);
    }
}
