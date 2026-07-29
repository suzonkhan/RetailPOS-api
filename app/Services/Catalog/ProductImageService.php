<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductImageService
{
    public function listForProduct(Product $product)
    {
        $this->authorizeProductAccess($product);

        return $product->images()->get();
    }

    public function storeForProduct(Product $product, UploadedFile $file, int $sortOrder = 0): ProductImage
    {
        $this->authorizeProductAccess($product);

        $path = $file->store("products/{$product->tenant_id}", 'public');

        return ProductImage::query()->create([
            'tenant_id' => $product->tenant_id,
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'path' => $path,
            'disk' => 'public',
            'sort_order' => $sortOrder,
        ]);
    }

    public function delete(Product $product, ProductImage $image): void
    {
        $this->authorizeProductAccess($product);

        if ($image->product_id !== $product->id) {
            abort(404);
        }

        Storage::disk($image->disk)->delete($image->path);
        $image->delete();
    }

    private function authorizeProductAccess(Product $product): void
    {
        $store = request()->user()->tenant?->store;

        if ($store === null || (int) $product->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
