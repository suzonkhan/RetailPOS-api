<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateTenantSettingsRequest;
use App\Http\Resources\TenantSettingsResource;
use App\Services\Settings\TenantSettingsService;

class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantSettingsService $tenantSettingsService,
    ) {}

    public function show(): TenantSettingsResource
    {
        $resolved = $this->tenantSettingsService->resolve(request()->user());

        return TenantSettingsResource::make($resolved);
    }

    public function update(UpdateTenantSettingsRequest $request): TenantSettingsResource
    {
        $resolved = $this->tenantSettingsService->update(
            $request->user(),
            $request->validated()
        );

        return TenantSettingsResource::make($resolved);
    }
}
