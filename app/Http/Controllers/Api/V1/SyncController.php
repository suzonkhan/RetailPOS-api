<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncPullRequest;
use App\Http\Requests\Sync\SyncPushRequest;
use App\Services\Sync\DeviceRegistrationService;
use App\Services\Sync\SyncPullService;
use App\Services\Sync\SyncPushService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SyncController extends Controller
{
    public function __construct(
        private readonly DeviceRegistrationService $devices,
        private readonly SyncPullService $pullService,
        private readonly SyncPushService $pushService,
    ) {}

    public function pull(SyncPullRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $device = $this->resolveDevice($request->user(), $validated['device_id']);

        $since = Carbon::parse($validated['since'])->utc();

        return response()->json(
            $this->pullService->pull($request->user(), $device, $since)
        );
    }

    public function push(SyncPushRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $device = $this->devices->resolveOrRegister(
            $request->user(),
            $validated['device_id'],
            $validated['device_name'] ?? null,
        );

        return response()->json(
            $this->pushService->push($request->user(), $device, $validated)
        );
    }

    private function resolveDevice($user, string $deviceUuid)
    {
        $device = $this->devices->findForUser($user, $deviceUuid);

        if ($device === null) {
            throw ValidationException::withMessages([
                'device_id' => ['Device is not registered. Register via POST /devices or include device on first push.'],
            ]);
        }

        return $device;
    }
}
