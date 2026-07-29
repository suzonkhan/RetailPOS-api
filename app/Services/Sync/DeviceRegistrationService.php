<?php

namespace App\Services\Sync;

use App\Models\Device;
use App\Models\User;

class DeviceRegistrationService
{
    public function findForUser(User $user, string $deviceUuid): ?Device
    {
        return Device::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('uuid', $deviceUuid)
            ->first();
    }

    public function register(User $user, string $deviceUuid, ?string $name = null): Device
    {
        return Device::query()->updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'uuid' => $deviceUuid,
            ],
            [
                'user_id' => $user->id,
                'name' => $name,
            ]
        );
    }

    public function resolveOrRegister(User $user, string $deviceUuid, ?string $name = null): Device
    {
        $device = $this->findForUser($user, $deviceUuid);

        if ($device !== null) {
            if ($name !== null && $device->name !== $name) {
                $device->name = $name;
                $device->save();
            }

            return $device;
        }

        return $this->register($user, $deviceUuid, $name);
    }

    public function touchSync(Device $device): void
    {
        $device->last_sync_at = now();
        $device->save();
    }
}
