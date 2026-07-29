<?php

namespace App\Services\Settings;

use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantSettingsService
{
    /**
     * @return array{store: Store, settings: StoreSetting}
     */
    public function resolve(User $user): array
    {
        $store = $user->tenant?->store;

        if ($store === null) {
            abort(404, 'Store not found.');
        }

        $settings = StoreSetting::query()->firstOrCreate(
            ['store_id' => $store->id],
            [
                'tenant_id' => $user->tenant_id,
                'vat_adjust_on_sale' => false,
            ]
        );

        return ['store' => $store->fresh(), 'settings' => $settings];
    }

    /**
     * @return array{store: Store, settings: StoreSetting}
     */
    public function update(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            ['store' => $store, 'settings' => $settings] = $this->resolve($user);

            if (array_key_exists('name', $data)) {
                $store->name = $data['name'];
            }

            if (array_key_exists('phone', $data)) {
                $store->phone = $data['phone'];
            }

            if (array_key_exists('address', $data)) {
                $store->address = $data['address'];
            }

            if ($store->isDirty()) {
                $store->save();
            }

            if (array_key_exists('default_vat_percent', $data)) {
                $settings->default_vat_percent = $data['default_vat_percent'];
            }

            if (array_key_exists('vat_adjust_on_sale', $data)) {
                $settings->vat_adjust_on_sale = $data['vat_adjust_on_sale'];
            }

            if ($settings->isDirty()) {
                $settings->save();
            } elseif ($store->wasChanged()) {
                $settings->touch();
            }

            return ['store' => $store->fresh(), 'settings' => $settings->fresh()];
        });
    }
}
