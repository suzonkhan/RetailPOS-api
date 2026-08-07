<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Uom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductCsvService
{
    public const HEADERS = [
        'name',
        'sku',
        'barcode',
        'description',
        'category',
        'supplier',
        'brand',
        'selling_price',
        'cost_price',
        'uom',
        'vat_rate',
        'vat_type',
        'min_stock_quantity',
        'is_active',
        'is_negotiable',
        'ask_qty_on_add',
        'manage_inventory',
    ];

    public const MAX_ROWS = 1000;

    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly CatalogPlanLimitService $planLimits,
        private readonly ProductService $productService,
    ) {}

    public function templateResponse(): StreamedResponse
    {
        return $this->streamCsv('product-import-template.csv', function ($out): void {
            fputcsv($out, self::HEADERS);
            fputcsv($out, [
                'Rice 5kg',
                'RICE-5',
                '',
                'Premium rice',
                'Grocery',
                'ACME Foods',
                '',
                '550',
                '480',
                'kg',
                '',
                '',
                '10',
                '1',
                '0',
                '0',
                '1',
            ]);
        });
    }

    public function exportForUser(User $user, array $filters = []): StreamedResponse
    {
        $store = $this->catalogScope->resolveStore($user);
        $branchSlug = $this->safeFilename($store->name);

        $query = Product::query()
            ->where('store_id', $store->id)
            ->where('has_variants', false)
            ->with(['category', 'supplier', 'brand'])
            ->orderBy('name');

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term);
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

        return $this->streamCsv("products-{$branchSlug}.csv", function ($out) use ($query): void {
            fputcsv($out, self::HEADERS);

            $query->chunk(200, function ($products) use ($out): void {
                foreach ($products as $product) {
                    fputcsv($out, [
                        $product->name,
                        $product->sku,
                        $product->barcode,
                        $product->description,
                        $product->category?->name,
                        $product->supplier?->name,
                        $product->brand?->name,
                        $product->selling_price,
                        $product->cost_price,
                        $product->uom ?? 'pcs',
                        $product->vat_rate,
                        $product->vat_type,
                        $product->min_stock_quantity,
                        $product->is_active ? '1' : '0',
                        $product->is_negotiable ? '1' : '0',
                        $product->ask_qty_on_add ? '1' : '0',
                        $product->manage_inventory ? '1' : '0',
                    ]);
                }
            });
        });
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<array{row: int, message: string}>, branch_id: int, branch_name: string}
     */
    public function importForUser(User $user, UploadedFile $file): array
    {
        $store = $this->catalogScope->resolveStore($user);
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['Could not read the uploaded CSV file.'],
            ]);
        }

        try {
            $headerRow = fgetcsv($handle);

            if ($headerRow === false) {
                throw ValidationException::withMessages([
                    'file' => ['The CSV file is empty.'],
                ]);
            }

            $headers = $this->normalizeHeaders($headerRow);
            $this->assertRequiredHeaders($headers);

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            $rowNumber = 1;

            while (($raw = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($this->rowIsEmpty($raw)) {
                    continue;
                }

                if (($created + $updated + $skipped + count($errors)) >= self::MAX_ROWS) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => 'Import stopped: maximum of '.self::MAX_ROWS.' data rows per file.',
                    ];
                    break;
                }

                try {
                    $assoc = $this->mapRow($headers, $raw);
                    $result = $this->importRow($user, $store, $assoc);

                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (ValidationException $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => collect($e->errors())->flatten()->first() ?? 'Invalid row.',
                    ];
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Failed to import row.',
                    ];
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'branch_id' => (int) $store->id,
            'branch_name' => $store->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return 'created'|'updated'|'skipped'
     */
    private function importRow(User $user, Store $store, array $row): string
    {
        $payload = $this->normalizeRowPayload($row);

        if (($payload['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                'name' => ['Name is required.'],
            ]);
        }

        if (($payload['category'] ?? '') === '') {
            throw ValidationException::withMessages([
                'category' => ['Category is required.'],
            ]);
        }

        return DB::transaction(function () use ($user, $store, $payload) {
            $sku = $payload['sku'] !== '' ? $payload['sku'] : null;
            $barcode = $payload['barcode'] !== '' ? $payload['barcode'] : null;
            $existing = $this->findExistingProduct($store, $sku, $barcode);

            $category = $this->resolveCategory($user, $store, $payload['category']);
            $supplierId = $this->resolveOptionalNamed(
                $store,
                Supplier::class,
                $payload['supplier'] ?? null,
            );
            $brandId = $this->resolveOptionalNamed(
                $store,
                Brand::class,
                $payload['brand'] ?? null,
            );

            $data = [
                'name' => $payload['name'],
                'sku' => $sku,
                'barcode' => $barcode,
                'description' => $payload['description'] !== '' ? $payload['description'] : null,
                'category_id' => $category->id,
                'supplier_id' => $supplierId,
                'brand_id' => $brandId,
                'selling_price' => $payload['selling_price'] ?? 0,
                'cost_price' => $payload['cost_price'],
                'uom' => $payload['uom'] ?: 'pcs',
                'vat_rate' => $payload['vat_rate'],
                'vat_type' => $payload['vat_type'],
                'min_stock_quantity' => $payload['min_stock_quantity'],
                'is_active' => $payload['is_active'],
                'is_negotiable' => $payload['is_negotiable'],
                'ask_qty_on_add' => $payload['ask_qty_on_add'],
                'manage_inventory' => $payload['manage_inventory'],
            ];

            $this->validateProductPayload($store, $data, $existing?->id);

            if ($existing !== null) {
                if ($existing->has_variants) {
                    throw ValidationException::withMessages([
                        'sku' => ['Cannot update a product that has variations via CSV.'],
                    ]);
                }

                $this->productService->update($existing, $data);

                return 'updated';
            }

            $this->planLimits->assertCanAddProduct($store);
            $this->productService->storeForUser($user, $data);

            return 'created';
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateProductPayload(Store $store, array $data, ?int $ignoreProductId = null): void
    {
        $vatTypes = config('retail360.vat_types', ['percent', 'fixed']);

        $skuRule = Rule::unique('products', 'sku')->where('store_id', $store->id);
        if ($ignoreProductId !== null) {
            $skuRule = $skuRule->ignore($ignoreProductId);
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64', $skuRule],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'uom' => ['required', 'string', Rule::in(Uom::codes())],
            'vat_rate' => ['nullable', 'numeric', 'min:0'],
            'vat_type' => ['nullable', 'string', Rule::in($vatTypes), 'required_with:vat_rate'],
            'min_stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'is_negotiable' => ['boolean'],
            'ask_qty_on_add' => ['boolean'],
            'manage_inventory' => ['boolean'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    private function findExistingProduct(Store $store, ?string $sku, ?string $barcode): ?Product
    {
        if ($sku !== null && $sku !== '') {
            $bySku = Product::query()
                ->where('store_id', $store->id)
                ->where('sku', $sku)
                ->first();

            if ($bySku !== null) {
                return $bySku;
            }
        }

        if ($barcode !== null && $barcode !== '') {
            return Product::query()
                ->where('store_id', $store->id)
                ->where('barcode', $barcode)
                ->where('has_variants', false)
                ->first();
        }

        return null;
    }

    private function resolveCategory(User $user, Store $store, string $name): Category
    {
        $existing = Category::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->planLimits->assertCanAddCategory($store);

        return Category::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $name,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * @param  class-string<Supplier|Brand>  $modelClass
     */
    private function resolveOptionalNamed(Store $store, string $modelClass, ?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $name = trim($name);

        $existing = $modelClass::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $created = $modelClass::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'name' => $name,
            'is_active' => true,
        ]);

        return (int) $created->id;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRowPayload(array $row): array
    {
        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'sku' => trim((string) ($row['sku'] ?? '')),
            'barcode' => trim((string) ($row['barcode'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'category' => trim((string) ($row['category'] ?? '')),
            'supplier' => trim((string) ($row['supplier'] ?? '')),
            'brand' => trim((string) ($row['brand'] ?? '')),
            'selling_price' => $this->nullableNumber($row['selling_price'] ?? null) ?? 0,
            'cost_price' => $this->nullableNumber($row['cost_price'] ?? null),
            'uom' => strtolower(trim((string) ($row['uom'] ?? 'pcs'))) ?: 'pcs',
            'vat_rate' => $this->nullableNumber($row['vat_rate'] ?? null),
            'vat_type' => $this->nullableString($row['vat_type'] ?? null),
            'min_stock_quantity' => $this->nullableNumber($row['min_stock_quantity'] ?? null),
            'is_active' => $this->toBool($row['is_active'] ?? true, true),
            'is_negotiable' => $this->toBool($row['is_negotiable'] ?? false, false),
            'ask_qty_on_add' => $this->toBool($row['ask_qty_on_add'] ?? false, false),
            'manage_inventory' => $this->toBool($row['manage_inventory'] ?? false, false),
        ];
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'value' => ["Invalid number: {$value}"],
            ]);
        }

        return (float) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : strtolower($trimmed);
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return list<string>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        return array_map(function ($header) {
            $value = strtolower(trim((string) $header));
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

            return $value;
        }, $headerRow);
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertRequiredHeaders(array $headers): void
    {
        foreach (['name', 'category'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw ValidationException::withMessages([
                    'file' => ["CSV must include a \"{$required}\" column."],
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $raw
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $raw): array
    {
        $assoc = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $assoc[$header] = $raw[$index] ?? null;
        }

        return $assoc;
    }

    /**
     * @param  list<string|null>  $raw
     */
    private function rowIsEmpty(array $raw): bool
    {
        foreach ($raw as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function safeFilename(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '', '-'));

        return $slug !== '' ? $slug : 'branch';
    }
}
