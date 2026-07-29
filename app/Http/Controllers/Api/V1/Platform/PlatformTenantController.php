<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdatePlatformTenantRequest;
use App\Http\Resources\Platform\PlatformTenantDetailResource;
use App\Http\Resources\Platform\PlatformTenantListResource;
use App\Models\Tenant;
use App\Services\Platform\PlatformTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformTenantController extends Controller
{
    public function __construct(
        private readonly PlatformTenantService $platformTenants,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $paginator = $this->platformTenants->list(
            request()->only(['search', 'status', 'page', 'per_page'])
        );

        return PlatformTenantListResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Tenant $tenant): PlatformTenantDetailResource
    {
        $tenant->load(['plan', 'store']);

        return PlatformTenantDetailResource::make($tenant);
    }

    public function update(UpdatePlatformTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $tenant = $this->platformTenants->update($tenant, $request->validated());

        return PlatformTenantDetailResource::make($tenant)
            ->response()
            ->setStatusCode(200);
    }
}
