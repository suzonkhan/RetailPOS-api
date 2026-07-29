<?php

namespace App\Http\Resources;

use App\Models\Store;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncSettingsResource extends JsonResource
{
    /**
     * @param  array{store: Store, settings: StoreSetting}  $resource
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource['store'];
        /** @var StoreSetting $settings */
        $settings = $this->resource['settings'];

        return [
            'uuid' => $settings->uuid,
            'store_id' => $store->id,
            'name' => $store->name,
            'phone' => $store->phone,
            'address' => $store->address,
            'default_vat_percent' => $settings->default_vat_percent !== null
                ? (float) $settings->default_vat_percent
                : null,
            'vat_adjust_on_sale' => $settings->vat_adjust_on_sale,
            'updated_at' => $settings->updated_at?->toIso8601String(),
            'deleted_at' => $settings->deleted_at?->toIso8601String(),
        ];
    }
}
