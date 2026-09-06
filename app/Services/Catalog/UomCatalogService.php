<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreUom;
use App\Models\StoreUomConversion;
use App\Support\Uom;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UomCatalogService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
    ) {}

    public function seedDefaults(Store $store): void
    {
        if (StoreUom::query()->where('store_id', $store->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($store): void {
            $codeToId = [];

            foreach (Uom::all() as $index => $template) {
                $uom = StoreUom::query()->create([
                    'tenant_id' => $store->tenant_id,
                    'store_id' => $store->id,
                    'code' => $template['code'],
                    'label' => $template['label'],
                    'fractional' => (bool) $template['fractional'],
                    'is_system' => true,
                    'is_active' => true,
                    'sort_order' => $index,
                ]);

                $codeToId[$template['code']] = $uom->id;
            }

            foreach (config('retail360.uom_conversions', []) as $pair) {
                $fromId = $codeToId[$pair['from']] ?? null;
                $toId = $codeToId[$pair['to']] ?? null;

                if ($fromId === null || $toId === null) {
                    continue;
                }

                StoreUomConversion::query()->create([
                    'tenant_id' => $store->tenant_id,
                    'store_id' => $store->id,
                    'from_uom_id' => $fromId,
                    'to_uom_id' => $toId,
                    'factor' => $pair['factor'],
                ]);
            }
        });
    }

    /**
     * @return Collection<int, StoreUom>
     */
    public function listForStore(Store $store): Collection
    {
        return StoreUom::query()
            ->where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }

    /**
     * @return Collection<int, StoreUomConversion>
     */
    public function conversionsForStore(Store $store): Collection
    {
        return StoreUomConversion::query()
            ->where('store_id', $store->id)
            ->with(['fromUom', 'toUom'])
            ->orderBy('id')
            ->get();
    }

    public function findByCode(Store $store, string $code): ?StoreUom
    {
        return StoreUom::query()
            ->where('store_id', $store->id)
            ->where('code', $code)
            ->first();
    }

    public function labelForStore(Store $store, string $code): string
    {
        return $this->labelForStoreId((int) $store->id, $code);
    }

    public function labelForStoreId(int $storeId, string $code): string
    {
        $row = StoreUom::query()
            ->where('store_id', $storeId)
            ->where('code', $code)
            ->first();

        return $row?->label ?? Uom::label($code);
    }

    public function isFractionalForStore(Store $store, string $code): bool
    {
        return $this->isFractionalForStoreId((int) $store->id, $code);
    }

    public function isFractionalForStoreId(int $storeId, string $code): bool
    {
        $row = StoreUom::query()
            ->where('store_id', $storeId)
            ->where('code', $code)
            ->first();

        return $row !== null
            ? (bool) $row->fractional
            : Uom::isFractional($code);
    }

    /**
     * @return list<string>
     */
    public function activeCodesForStore(Store $store): array
    {
        return StoreUom::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('code')
            ->all();
    }

    public function createForUser(Store $store, array $data): StoreUom
    {
        $code = strtolower(trim((string) $data['code']));

        if ($this->findByCode($store, $code) !== null) {
            throw ValidationException::withMessages([
                'code' => ['This unit code already exists for this store.'],
            ]);
        }

        return StoreUom::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'code' => $code,
            'label' => trim((string) $data['label']),
            'fractional' => (bool) ($data['fractional'] ?? false),
            'is_system' => false,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    public function update(StoreUom $uom, array $data): StoreUom
    {
        if ($uom->is_system && isset($data['code']) && $data['code'] !== $uom->code) {
            throw ValidationException::withMessages([
                'code' => ['System unit codes cannot be changed.'],
            ]);
        }

        if (! $uom->is_system && isset($data['code'])) {
            $code = strtolower(trim((string) $data['code']));
            $exists = StoreUom::query()
                ->where('store_id', $uom->store_id)
                ->where('code', $code)
                ->where('id', '!=', $uom->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'code' => ['This unit code already exists for this store.'],
                ]);
            }

            $uom->code = $code;
        }

        if (isset($data['label'])) {
            $uom->label = trim((string) $data['label']);
        }

        if (array_key_exists('fractional', $data)) {
            $uom->fractional = (bool) $data['fractional'];
        }

        if (array_key_exists('is_active', $data)) {
            $uom->is_active = (bool) $data['is_active'];
        }

        if (array_key_exists('sort_order', $data)) {
            $uom->sort_order = (int) $data['sort_order'];
        }

        $uom->save();

        return $uom->fresh();
    }

    public function delete(StoreUom $uom): void
    {
        if ($uom->is_system) {
            throw ValidationException::withMessages([
                'uom' => ['System units cannot be deleted.'],
            ]);
        }

        $inUse = Product::query()
            ->where('store_id', $uom->store_id)
            ->where('uom', $uom->code)
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'uom' => ['This unit is used by one or more products.'],
            ]);
        }

        $referenced = StoreUomConversion::query()
            ->where('store_id', $uom->store_id)
            ->where(function ($q) use ($uom): void {
                $q->where('from_uom_id', $uom->id)
                    ->orWhere('to_uom_id', $uom->id);
            })
            ->exists();

        if ($referenced) {
            throw ValidationException::withMessages([
                'uom' => ['Remove conversion pairs for this unit before deleting it.'],
            ]);
        }

        $uom->delete();
    }

    public function createConversion(Store $store, array $data): StoreUomConversion
    {
        $fromId = (int) $data['from_uom_id'];
        $toId = (int) $data['to_uom_id'];

        if ($fromId === $toId) {
            throw ValidationException::withMessages([
                'to_uom_id' => ['From and to units must be different.'],
            ]);
        }

        $this->assertUomBelongsToStore($store, $fromId);
        $this->assertUomBelongsToStore($store, $toId);

        if ($this->conversionPairExists($store->id, $fromId, $toId)) {
            throw ValidationException::withMessages([
                'from_uom_id' => ['A conversion between these units already exists.'],
            ]);
        }

        return StoreUomConversion::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'from_uom_id' => $fromId,
            'to_uom_id' => $toId,
            'factor' => $data['factor'],
        ])->load(['fromUom', 'toUom']);
    }

    public function updateConversion(StoreUomConversion $conversion, array $data): StoreUomConversion
    {
        $store = $conversion->store;
        $fromId = (int) ($data['from_uom_id'] ?? $conversion->from_uom_id);
        $toId = (int) ($data['to_uom_id'] ?? $conversion->to_uom_id);

        if ($fromId === $toId) {
            throw ValidationException::withMessages([
                'to_uom_id' => ['From and to units must be different.'],
            ]);
        }

        $this->assertUomBelongsToStore($store, $fromId);
        $this->assertUomBelongsToStore($store, $toId);

        if ($this->conversionPairExists($store->id, $fromId, $toId, $conversion->id)) {
            throw ValidationException::withMessages([
                'from_uom_id' => ['A conversion between these units already exists.'],
            ]);
        }

        $conversion->fill([
            'from_uom_id' => $fromId,
            'to_uom_id' => $toId,
            'factor' => $data['factor'] ?? $conversion->factor,
        ]);
        $conversion->save();

        return $conversion->fresh(['fromUom', 'toUom']);
    }

    public function deleteConversion(StoreUomConversion $conversion): void
    {
        $conversion->delete();
    }

    private function assertUomBelongsToStore(Store $store, int $uomId): void
    {
        $exists = StoreUom::query()
            ->where('store_id', $store->id)
            ->whereKey($uomId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'from_uom_id' => ['Unit is invalid for this store.'],
            ]);
        }
    }

    private function conversionPairExists(
        int $storeId,
        int $fromId,
        int $toId,
        ?int $ignoreId = null,
    ): bool {
        return StoreUomConversion::query()
            ->where('store_id', $storeId)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($fromId, $toId): void {
                $q->where(function ($inner) use ($fromId, $toId): void {
                    $inner->where('from_uom_id', $fromId)
                        ->where('to_uom_id', $toId);
                })->orWhere(function ($inner) use ($fromId, $toId): void {
                    $inner->where('from_uom_id', $toId)
                        ->where('to_uom_id', $fromId);
                });
            })
            ->exists();
    }
}
