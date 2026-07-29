<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\RegisterDeviceRequest;
use App\Services\Sync\DeviceRegistrationService;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceRegistrationService $devices,
    ) {}

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $device = $this->devices->register(
            $request->user(),
            $validated['device_id'],
            $validated['name'] ?? null,
        );

        return response()->json([
            'device_id' => $device->uuid,
            'name' => $device->name,
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            'registered_at' => $device->created_at?->toIso8601String(),
        ], 201);
    }
}
