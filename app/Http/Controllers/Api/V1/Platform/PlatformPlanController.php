<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformPlanRequest;
use App\Http\Requests\Platform\UpdatePlatformPlanRequest;
use App\Http\Resources\Platform\PlatformPlanResource;
use App\Models\Plan;
use App\Services\Platform\PlatformPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformPlanController extends Controller
{
    public function __construct(
        private readonly PlatformPlanService $platformPlans,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return PlatformPlanResource::collection($this->platformPlans->list());
    }

    public function store(StorePlatformPlanRequest $request): JsonResponse
    {
        $plan = $this->platformPlans->create($request->validated());
        $plan->loadCount('tenants');

        return PlatformPlanResource::make($plan)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Plan $plan): PlatformPlanResource
    {
        $plan->loadCount('tenants');

        return PlatformPlanResource::make($plan);
    }

    public function update(UpdatePlatformPlanRequest $request, Plan $plan): PlatformPlanResource
    {
        $plan = $this->platformPlans->update($plan, $request->validated());

        return PlatformPlanResource::make($plan);
    }
}
