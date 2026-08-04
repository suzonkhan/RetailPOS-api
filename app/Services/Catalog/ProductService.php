<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Support\BarcodeGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly CatalogPlanLimitService $planLimits,
        private readonly BarcodeGenerator $barcodes,
    ) {}

    public function listForUser(User $user, array $filters): LengthAwarePaginator
    {
        $store = $this->catalogScope->resolveStore($user);

        $query = Product::query()
            ->where('store_id', $store->id)
            ->with(['category', 'supplier', 'brand', 'primaryImage'])
            ->withCount([
                'variants as active_variants_count' => fn ($q) => $q->where('is_active', true),
            ]);

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term)
                    ->orWhereHas('variants', function ($vq) use ($term) {
                        $vq->where('sku', 'like', $term)
                            ->orWhere('barcode', 'like', $term);
                    });
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (! empty($filters['low_stock'])) {
            $query->where('manage_inventory', true)
                ->whereNotNull('min_stock_quantity')
                ->whereColumn('stock_quantity', '<=', 'min_stock_quantity');
        }

        if (! empty($filters['expired'])) {
            $query->whereNotNull('expiration_date')
                ->whereDate('expiration_date', '<', now()->toDateString());
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        if (! empty($filters['include_variants'])) {
            $query->with([
                'variants' => fn ($q) => $q->where('is_active', true)->with('options.attribute'),
            ]);
        }

        return $query
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function storeForUser(User $user, array $data): Product
    {
        $store = $this->catalogScope->resolveStore($user);
        $tenant = $user->tenant;

        $this->planLimits->assertCanAddProduct($store);

        $manageInventory = $data['manage_inventory'] ?? false;
        $barcode = $this->resolveBarcode(
            $data['barcode'] ?? null,
            existing: null,
            tenantId: (int) $user->tenant_id,
            storeId: (int) $store->id,
        );

        $product = Product::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'category_id' => $data['category_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'barcode' => $barcode,
            'description' => $data['description'] ?? null,
            'selling_price' => $data['selling_price'] ?? 0,
            'cost_price' => $data['cost_price'] ?? null,
            'stock_quantity' => 0,
            'min_stock_quantity' => $manageInventory ? ($data['min_stock_quantity'] ?? null) : null,
            'expiration_date' => null,
            'uom' => $data['uom'] ?? 'pcs',
            'vat_rate' => $data['vat_rate'] ?? null,
            'vat_type' => $data['vat_type'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_negotiable' => $data['is_negotiable'] ?? false,
            'ask_qty_on_add' => $data['ask_qty_on_add'] ?? false,
            'manage_inventory' => $data['manage_inventory'] ?? false,
        ]);

        return $product->load(['category', 'supplier', 'brand']);
    }

    public function update(Product $product, array $data): Product
    {
        if ($product->has_variants) {
            unset($data['sku'], $data['barcode'], $data['stock_quantity']);
        }

        $barcode = $product->barcode;
        if (array_key_exists('barcode', $data) && ! $product->has_variants) {
            $barcode = $this->resolveBarcode(
                $data['barcode'],
                existing: $product->barcode,
                tenantId: (int) $product->tenant_id,
                storeId: (int) $product->store_id,
            );
        }

        $product->fill([
            'category_id' => $data['category_id'] ?? $product->category_id,
            'supplier_id' => array_key_exists('supplier_id', $data) ? $data['supplier_id'] : $product->supplier_id,
            'brand_id' => array_key_exists('brand_id', $data) ? $data['brand_id'] : $product->brand_id,
            'name' => $data['name'] ?? $product->name,
            'sku' => array_key_exists('sku', $data) ? $data['sku'] : $product->sku,
            'barcode' => $barcode,
            'description' => array_key_exists('description', $data) ? $data['description'] : $product->description,
            'selling_price' => $data['selling_price'] ?? $product->selling_price,
            'cost_price' => array_key_exists('cost_price', $data) ? $data['cost_price'] : $product->cost_price,
            'min_stock_quantity' => ($data['manage_inventory'] ?? $product->manage_inventory)
                ? (array_key_exists('min_stock_quantity', $data)
                    ? $data['min_stock_quantity']
                    : $product->min_stock_quantity)
                : null,
            'uom' => $data['uom'] ?? $product->uom,
            'vat_rate' => array_key_exists('vat_rate', $data) ? $data['vat_rate'] : $product->vat_rate,
            'vat_type' => array_key_exists('vat_type', $data) ? $data['vat_type'] : $product->vat_type,
            'is_active' => $data['is_active'] ?? $product->is_active,
            'is_negotiable' => $data['is_negotiable'] ?? $product->is_negotiable,
            'ask_qty_on_add' => $data['ask_qty_on_add'] ?? $product->ask_qty_on_add,
            'manage_inventory' => $data['manage_inventory'] ?? $product->manage_inventory,
        ]);

        $product->save();

        return $product->load(['category', 'supplier', 'brand']);
    }

    /**
     * Use the provided barcode when present; otherwise keep existing or auto-generate.
     */
    private function resolveBarcode(mixed $incoming, ?string $existing, int $tenantId, int $storeId): string
    {
        $value = is_string($incoming) ? trim($incoming) : '';

        if ($value !== '') {
            return $value;
        }

        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        return $this->barcodes->uniqueForTenant($tenantId, $storeId);
    }
}
