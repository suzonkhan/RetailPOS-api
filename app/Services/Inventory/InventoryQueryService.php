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
            ->with(['product', 'productVariant.options.attribute']);

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
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
