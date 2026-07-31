<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformCouponRequest;
use App\Http\Requests\Platform\UpdatePlatformCouponRequest;
use App\Http\Resources\Platform\PlatformCouponResource;
use App\Models\Coupon;
use App\Services\Platform\PlatformCouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformCouponController extends Controller
{
    public function __construct(
        private readonly PlatformCouponService $platformCoupons,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return PlatformCouponResource::collection($this->platformCoupons->list());
    }

    public function store(StorePlatformCouponRequest $request): JsonResponse
    {
        $coupon = $this->platformCoupons->create($request->validated());

        return PlatformCouponResource::make($coupon)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePlatformCouponRequest $request, Coupon $coupon): PlatformCouponResource
    {
        $coupon = $this->platformCoupons->update($coupon, $request->validated());

        return PlatformCouponResource::make($coupon);
    }
}
