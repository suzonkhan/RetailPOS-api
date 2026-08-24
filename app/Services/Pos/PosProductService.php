<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CatalogScopeService;
use App\Services\Inventory\LotService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PosProductService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly LotService $lots,
    ) {}

    public function listForUser(User $user, array $filters): LengthAwarePaginator
    {
        $store = $this->catalogScope->resolveStore($user);
        $today = now()->toDateString();

        $query = Product::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with(['category', 'primaryImage'])
            ->withCount([
                'variants as active_variants_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->withSum(
                ['stockLots as sellable_remaining' => fn (Builder $lots) => $this->lots->constrainSellableLots($lots, $today)],
                'quantity_remaining'
            )
            ->withMin(
                ['stockLots as soonest_sellable_expiry' => function (Builder $lots) use ($today) {
                    $this->lots->constrainSellableLots($lots, $today);
                    $lots->whereNotNull('expiration_date');
                }],
                'expiration_date'
            );

        $this->constrainSellable($query, $today);

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term)
                    ->orWhereHas('variants', function ($vq) use ($term) {
                        $vq->where('is_active', true)
                            ->where(function ($inner) use ($term) {
                                $inner->where('sku', 'like', $term)
                                    ->orWhere('barcode', 'like', $term);
                            });
                    });
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 24), 1), 100);

        $query->with([
            'variants' => function ($q) use ($today) {
                $q->where('is_active', true)
                    ->with('options.attribute')
                    ->where(function ($vq) use ($today) {
                        $vq->whereHas('product', fn ($p) => $p->where('manage_inventory', false))
                            ->orWhere(function ($sellable) use ($today) {
                                $this->constrainSellableLots($sellable, $today);
                            });
                    })
                    ->withSum(
                        ['stockLots as sellable_remaining' => fn (Builder $lots) => $this->lots->constrainSellableLots($lots, $today)],
                        'quantity_remaining'
                    );
            },
        ]);

        $paginator = $query
            ->orderBy('name')
            ->paginate($perPage);

        $paginator->getCollection()->each(
            fn (Product $product) => $this->syncSellableQuantities($product)
        );

        return $paginator;
    }

    /**
     * Active POS items only: skip inactive, out-of-stock (when inventory is tracked),
     * and expired lots when an expiration date is set.
     */
    private function constrainSellable(Builder $query, string $today): void
    {
        $query->where(function (Builder $q) use ($today) {
            $q->where(function (Builder $nonInventory) use ($today) {
                $nonInventory->where('manage_inventory', false)
                    ->where(function (Builder $exp) use ($today) {
                        $exp->whereNull('expiration_date')
                            ->orWhereDate('expiration_date', '>=', $today);
                    });
            })->orWhere(function (Builder $inventory) use ($today) {
                $inventory->where('manage_inventory', true)
                    ->where(function (Builder $stock) use ($today) {
                        $this->constrainSellableLots($stock, $today);
                    });
            });
        });
    }

    private function constrainSellableLots(Builder $query, string $today): void
    {
        $query->whereHas(
            'stockLots',
            fn (Builder $lots) => $this->lots->constrainSellableLots($lots, $today)
        );
    }

    private function syncSellableQuantities(Product $product): void
    {
        if (! $product->manage_inventory) {
            return;
        }

        $product->stock_quantity = round((float) ($product->sellable_remaining ?? 0), 3);

        if ($product->soonest_sellable_expiry) {
            $product->expiration_date = $product->soonest_sellable_expiry;
        } elseif ($product->stock_quantity > 0.0001) {
            $product->expiration_date = null;
        }

        if (! $product->relationLoaded('variants')) {
            return;
        }

        $product->variants->each(function (ProductVariant $variant) {
            $variant->stock_quantity = round((float) ($variant->sellable_remaining ?? 0), 3);
        });
    }
}
