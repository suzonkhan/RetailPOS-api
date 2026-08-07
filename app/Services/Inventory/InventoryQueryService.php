<?php

namespace App\Services\Inventory;

use App\Models\StockLot;
use App\Models\User;
use App\Services\Catalog\CatalogScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InventoryQueryService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
    ) {}

    public function listLotsForUser(User $user, array $filters): LengthAwarePaginator
    {
        $store = $this->catalogScope->resolveStore($user);

        $query = StockLot::query()
            ->where('store_id', $store->id)
            ->with(['product.category', 'product.supplier', 'product.brand', 'productVariant.options.attribute']);

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['search']) || ! empty($filters['category_id']) || ! empty($filters['supplier_id']) || ! empty($filters['brand_id'])) {
            $query->whereHas('product', function ($productQuery) use ($filters) {
                if (! empty($filters['search'])) {
                    $term = '%'.$filters['search'].'%';
                    $productQuery->where(function ($q) use ($term) {
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
                    $productQuery->where('category_id', $filters['category_id']);
                }

                if (! empty($filters['supplier_id'])) {
                    $productQuery->where('supplier_id', $filters['supplier_id']);
                }

                if (! empty($filters['brand_id'])) {
                    $productQuery->where('brand_id', $filters['brand_id']);
                }
            });
        }

        if (! empty($filters['expired'])) {
            $query->whereNotNull('expiration_date')
                ->whereDate('expiration_date', '<', now()->toDateString())
                ->where('quantity_remaining', '>', 0);
        }

        if (! empty($filters['has_remaining'])) {
            $query->where('quantity_remaining', '>', 0);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query
            ->orderBy('received_at')
            ->orderBy('id')
            ->paginate($perPage);
    }
}
