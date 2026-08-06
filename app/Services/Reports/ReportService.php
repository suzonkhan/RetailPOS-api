<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\CustomerDue;
use App\Models\DuePayment;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private const CURRENCY = 'BDT';

    /**
     * Physical table name for DB::raw() — includes DB_PREFIX (getTable() alone does not).
     */
    private function table(string $modelClass): string
    {
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;

        return $model->getConnection()->getTablePrefix().$model->getTable();
    }

    public function salesSummary(Store $store, Carbon $from, Carbon $to): array
    {
        $current = $this->salesSummaryMetrics($store, $from, $to);
        [$prevFrom, $prevTo] = $this->previousPeriod($from, $to);
        $previous = $this->salesSummaryMetrics($store, $prevFrom, $prevTo);
        $payments = $this->paymentBreakdown($store, $from, $to);

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous_from' => $prevFrom->toDateString(),
            'previous_to' => $prevTo->toDateString(),
            ...$current,
            'changes' => [
                'sale_count' => $this->changePercent($current['sale_count'], $previous['sale_count']),
                'gross_revenue' => $this->changePercent($current['gross_revenue'], $previous['gross_revenue']),
                'vat_total' => $this->changePercent($current['vat_total'], $previous['vat_total']),
                'discounts_total' => $this->changePercent($current['discounts_total'], $previous['discounts_total']),
                'returns_total' => $this->changePercent($current['returns_total'], $previous['returns_total']),
                'net_revenue' => $this->changePercent($current['net_revenue'], $previous['net_revenue']),
                'average_order_value' => $this->changePercent($current['average_order_value'], $previous['average_order_value']),
            ],
            'payment_methods' => $payments['data'],
            'payment_methods_total' => $payments['total_amount'],
        ];
    }

    public function salesTrend(Store $store, Carbon $from, Carbon $to, string $period): array
    {
        if ($period === 'hour' && $from->diffInHours($to) > 48) {
            // Hourly charts stay readable — clamp to the end day's 24 hours.
            $from = $to->copy()->startOfDay();
        }

        $sales = $this->completedSalesQuery($store, $from, $to)
            ->get(['created_at', 'total']);

        $returns = $this->returnsQuery($store, $from, $to)
            ->get(['created_at', 'total']);

        $buckets = $this->initializeBuckets($from, $to, $period);

        foreach ($sales as $sale) {
            $key = $this->bucketKey(\App\Support\AppTimezone::local($sale->created_at), $period);
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['sale_count']++;
            $buckets[$key]['gross_revenue'] += (float) $sale->total;
        }

        foreach ($returns as $return) {
            $key = $this->bucketKey(\App\Support\AppTimezone::local($return->created_at), $period);
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['returns_total'] += (float) $return->total;
        }

        $data = collect($buckets)
            ->map(function (array $bucket): array {
                $bucket['gross_revenue'] = round($bucket['gross_revenue'], 2);
                $bucket['returns_total'] = round($bucket['returns_total'], 2);
                $bucket['net_revenue'] = round($bucket['gross_revenue'] - $bucket['returns_total'], 2);

                return $bucket;
            })
            ->values()
            ->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period' => $period,
            'data' => $data,
        ];
    }

    public function topProducts(Store $store, Carbon $from, Carbon $to, int $limit, string $sortBy): array
    {
        $orderColumn = $sortBy === 'quantity' ? 'quantity' : 'revenue';
        $saleItems = $this->table(SaleItem::class);

        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.store_id', $store->id)
            ->whereIn('sales.status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy(
                'sale_items.product_id',
                'sale_items.product_name',
                'products.sku',
                'products.barcode',
            )
            ->select([
                'sale_items.product_id',
                'sale_items.product_name',
                'products.sku',
                'products.barcode',
                DB::raw("SUM({$saleItems}.quantity) as quantity"),
                DB::raw("SUM({$saleItems}.line_total) as revenue"),
                DB::raw("SUM({$saleItems}.line_total - ({$saleItems}.quantity * COALESCE({$saleItems}.unit_cost, 0))) as profit"),
            ])
            ->orderByDesc($orderColumn)
            ->limit($limit)
            ->get();

        $data = $rows->values()->map(fn ($row, int $index) => [
            'rank' => $index + 1,
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'sku' => $row->sku,
            'barcode' => $row->barcode,
            'quantity' => round((float) $row->quantity, 3),
            'revenue' => round((float) $row->revenue, 2),
            'profit' => round((float) $row->profit, 2),
        ])->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sort_by' => $sortBy,
            'data' => $data,
        ];
    }

    public function profitSummary(Store $store, Carbon $from, Carbon $to): array
    {
        $current = $this->profitSummaryMetrics($store, $from, $to);
        [$prevFrom, $prevTo] = $this->previousPeriod($from, $to);
        $previous = $this->profitSummaryMetrics($store, $prevFrom, $prevTo);

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous_from' => $prevFrom->toDateString(),
            'previous_to' => $prevTo->toDateString(),
            ...$current,
            'changes' => [
                'gross_revenue' => $this->changePercent($current['gross_revenue'], $previous['gross_revenue']),
                'returns_total' => $this->changePercent($current['returns_total'], $previous['returns_total']),
                'net_revenue' => $this->changePercent($current['net_revenue'], $previous['net_revenue']),
                'cogs' => $this->changePercent($current['cogs'], $previous['cogs']),
                'gross_profit' => $this->changePercent($current['gross_profit'], $previous['gross_profit']),
                'expenses_total' => $this->changePercent($current['expenses_total'], $previous['expenses_total']),
                'net_profit' => $this->changePercent($current['net_profit'], $previous['net_profit']),
            ],
        ];
    }

    public function paymentBreakdown(Store $store, Carbon $from, Carbon $to): array
    {
        $salePaymentsTable = $this->table(SalePayment::class);
        $duePaymentsTable = $this->table(DuePayment::class);

        $saleRows = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')
            ->where('sales.store_id', $store->id)
            ->whereIn('sales.status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereNull('sales.deleted_at')
            ->whereNull('payment_methods.deleted_at')
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy(
                'sale_payments.payment_method_id',
                'payment_methods.name',
                'payment_methods.is_credit',
            )
            ->select([
                'sale_payments.payment_method_id',
                'payment_methods.name as payment_method_name',
                'payment_methods.is_credit',
                DB::raw("SUM({$salePaymentsTable}.amount) as sales_total"),
                DB::raw('COUNT(*) as sales_count'),
            ])
            ->get()
            ->keyBy(fn ($row) => (int) $row->payment_method_id);

        $dueRows = DuePayment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'due_payments.payment_method_id')
            ->where('due_payments.store_id', $store->id)
            ->whereNull('payment_methods.deleted_at')
            ->whereBetween('due_payments.created_at', [$from, $to])
            ->groupBy(
                'due_payments.payment_method_id',
                'payment_methods.name',
                'payment_methods.is_credit',
            )
            ->select([
                'due_payments.payment_method_id',
                'payment_methods.name as payment_method_name',
                'payment_methods.is_credit',
                DB::raw("SUM({$duePaymentsTable}.amount) as due_collections_total"),
                DB::raw('COUNT(*) as due_count'),
            ])
            ->get()
            ->keyBy(fn ($row) => (int) $row->payment_method_id);

        $data = $saleRows->keys()
            ->merge($dueRows->keys())
            ->unique()
            ->map(function ($methodId) use ($saleRows, $dueRows): array {
                $methodId = (int) $methodId;
                $sale = $saleRows->get($methodId);
                $due = $dueRows->get($methodId);
                $salesTotal = round((float) ($sale->sales_total ?? 0), 2);
                $dueTotal = round((float) ($due->due_collections_total ?? 0), 2);

                return [
                    'payment_method_id' => $methodId,
                    'payment_method_name' => $sale->payment_method_name ?? $due->payment_method_name,
                    'is_credit' => (bool) ($sale->is_credit ?? $due->is_credit ?? false),
                    'sales_total' => $salesTotal,
                    'due_collections_total' => $dueTotal,
                    'total' => round($salesTotal + $dueTotal, 2),
                    'payment_count' => (int) ($sale->sales_count ?? 0) + (int) ($due->due_count ?? 0),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_amount' => round(collect($data)->sum('total'), 2),
            'data' => $data,
        ];
    }

    public function businessSummary(Store $store, Carbon $from, Carbon $to): array
    {
        $salesSummary = $this->salesSummary($store, $from, $to);
        $profit = $this->profitSummary($store, $from, $to);

        $purchasesTotal = (float) Purchase::query()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->whereBetween('purchased_at', [$from, $to])
            ->sum('total');

        $customersOnSales = Sale::query()
            ->where('store_id', $store->id)
            ->whereIn('status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('customer_id')
            ->pluck('customer_id')
            ->unique()
            ->count();

        $totalCustomers = (int) Customer::query()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->count();

        $stockValue = (float) Product::query()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->where('manage_inventory', true)
            ->selectRaw('COALESCE(SUM(stock_quantity * COALESCE(cost_price, 0)), 0) as stock_value')
            ->value('stock_value');

        $lowStockCount = (int) Product::query()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->where('manage_inventory', true)
            ->whereNotNull('min_stock_quantity')
            ->whereColumn('stock_quantity', '<=', 'min_stock_quantity')
            ->count();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_sales' => $salesSummary['gross_revenue'],
            'net_sales' => $salesSummary['net_revenue'],
            'gross_profit' => $profit['gross_profit'],
            'total_purchases' => round($purchasesTotal, 2),
            'total_expenses' => $profit['expenses_total'],
            'total_orders' => $salesSummary['sale_count'],
            'customers_on_sales' => $customersOnSales,
            'total_customers' => $totalCustomers,
            'stock_value' => round($stockValue, 2),
            'low_stock_items' => $lowStockCount,
        ];
    }

    public function dailySales(Store $store, Carbon $dayStart, Carbon $dayEnd): array
    {
        $sales = Sale::query()
            ->with([
                'customer:id,name,mobile',
                'user:id,name',
                'items',
                'payments.paymentMethod:id,name',
            ])
            ->where('store_id', $store->id)
            ->whereIn('status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->orderBy('created_at')
            ->get();

        $data = $sales->map(function (Sale $sale) {
            $paymentNames = $sale->payments
                ->map(fn ($p) => $p->paymentMethod?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();

            return [
                'invoice_no' => '#'.$sale->id,
                'sale_id' => $sale->id,
                'datetime' => $sale->created_at?->toIso8601String(),
                'customer' => $sale->customer?->name,
                'cashier' => $sale->user?->name,
                'items_sold' => (int) $sale->items->sum('quantity'),
                'payment_methods' => $paymentNames,
                'discount' => round((float) ($sale->discount_amount ?? 0), 2),
                'grand_total' => round((float) $sale->total, 2),
                'items' => $sale->items->map(fn (SaleItem $item) => [
                    'product_name' => $item->product_name,
                    'sku' => $item->product_sku,
                    'quantity' => round((float) $item->quantity, 3),
                    'unit_price' => round((float) $item->unit_price, 2),
                    'line_total' => round((float) $item->line_total, 2),
                ])->all(),
            ];
        })->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $dayStart->toDateString(),
            'to' => $dayEnd->toDateString(),
            'data' => $data,
        ];
    }

    public function productSales(Store $store, Carbon $from, Carbon $to): array
    {
        $saleItems = $this->table(SaleItem::class);

        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.store_id', $store->id)
            ->whereIn('sales.status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy(
                'sale_items.product_id',
                'sale_items.product_name',
                'products.sku',
                'products.barcode',
            )
            ->select([
                'sale_items.product_id',
                'sale_items.product_name',
                'products.sku',
                'products.barcode',
                DB::raw("SUM({$saleItems}.quantity) as quantity"),
                DB::raw("SUM({$saleItems}.line_total) as sales_amount"),
                DB::raw("SUM({$saleItems}.line_total - ({$saleItems}.quantity * COALESCE({$saleItems}.unit_cost, 0))) as profit"),
            ])
            ->orderByDesc('sales_amount')
            ->get();

        $data = $rows->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'product' => $row->product_name,
            'sku' => $row->sku,
            'barcode' => $row->barcode,
            'quantity_sold' => round((float) $row->quantity, 3),
            'sales_amount' => round((float) $row->sales_amount, 2),
            'profit' => round((float) $row->profit, 2),
        ])->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $data,
        ];
    }

    public function currentStock(Store $store): array
    {
        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->where('manage_inventory', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock_quantity', 'cost_price', 'selling_price']);

        $data = $products->map(function (Product $product) {
            $stock = round((float) $product->stock_quantity, 3);
            $cost = round((float) ($product->cost_price ?? 0), 2);

            return [
                'product_id' => $product->id,
                'product' => $product->name,
                'sku' => $product->sku,
                'current_stock' => $stock,
                'purchase_price' => $cost,
                'selling_price' => round((float) $product->selling_price, 2),
                'stock_value' => round($stock * $cost, 2),
            ];
        })->all();

        return [
            'currency' => self::CURRENCY,
            'from' => null,
            'to' => null,
            'as_of' => now()->toDateString(),
            'data' => $data,
        ];
    }

    public function stockLedger(Store $store, Carbon $from, Carbon $to): array
    {
        $movements = StockMovement::query()
            ->with('product:id,name,sku')
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $saleIds = $movements
            ->where('reference_type', Sale::class)
            ->pluck('reference_id')
            ->unique()
            ->values();
        $adjustmentIds = $movements
            ->where('reference_type', StockAdjustment::class)
            ->pluck('reference_id')
            ->unique()
            ->values();

        $saleUsers = $saleIds->isEmpty()
            ? collect()
            : Sale::query()->with('user:id,name')->whereIn('id', $saleIds)->get()->keyBy('id');
        $adjustmentUsers = $adjustmentIds->isEmpty()
            ? collect()
            : StockAdjustment::query()->with('creator:id,name')->whereIn('id', $adjustmentIds)->get()->keyBy('id');

        $data = $movements->map(function (StockMovement $movement) use ($saleUsers, $adjustmentUsers) {
            $delta = (float) $movement->quantity_delta;
            $performedBy = null;

            if ($movement->reference_type === Sale::class) {
                $performedBy = $saleUsers->get($movement->reference_id)?->user?->name;
            } elseif ($movement->reference_type === StockAdjustment::class) {
                $performedBy = $adjustmentUsers->get($movement->reference_id)?->creator?->name;
            }

            $typeLabel = match ($movement->type) {
                StockMovement::TYPE_SALE => 'Sale',
                StockMovement::TYPE_RETURN => 'Return',
                StockMovement::TYPE_PURCHASE => 'Purchase',
                StockMovement::TYPE_ADJUSTMENT => 'Adjustment',
                default => ucfirst((string) $movement->type),
            };

            return [
                'date' => $movement->created_at?->toIso8601String(),
                'product' => $movement->product?->name,
                'product_id' => $movement->product_id,
                'reference' => $typeLabel.' #'.$movement->reference_id,
                'reference_type' => $movement->type,
                'stock_in' => $delta > 0 ? round($delta, 3) : 0.0,
                'stock_out' => $delta < 0 ? round(abs($delta), 3) : 0.0,
                'balance_stock' => round((float) $movement->quantity_after, 3),
                'performed_by' => $performedBy,
            ];
        })->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $data,
        ];
    }

    public function lowStock(Store $store): array
    {
        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->where('manage_inventory', true)
            ->whereNotNull('min_stock_quantity')
            ->whereColumn('stock_quantity', '<=', 'min_stock_quantity')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock_quantity', 'min_stock_quantity']);

        $data = $products->map(function (Product $product) {
            $current = round((float) $product->stock_quantity, 3);
            $min = round((float) $product->min_stock_quantity, 3);

            return [
                'product_id' => $product->id,
                'product' => $product->name,
                'sku' => $product->sku,
                'current_stock' => $current,
                'minimum_stock_level' => $min,
                'suggested_reorder_quantity' => round(max(0, $min - $current), 3),
            ];
        })->all();

        return [
            'currency' => self::CURRENCY,
            'from' => null,
            'to' => null,
            'as_of' => now()->toDateString(),
            'data' => $data,
        ];
    }

    public function expensesReport(Store $store, Carbon $from, Carbon $to): array
    {
        $expenses = Expense::query()
            ->with(['category:id,name', 'creator:id,name'])
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $data = $expenses->map(fn (Expense $expense) => [
            'date' => $expense->expense_date?->format('Y-m-d'),
            'expense_category' => $expense->category?->name,
            'description' => $expense->title ?: $expense->notes,
            'amount' => round((float) $expense->amount, 2),
            'added_by' => $expense->creator?->name,
        ])->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_amount' => round((float) $expenses->sum('amount'), 2),
            'data' => $data,
        ];
    }

    public function customerDues(Store $store): array
    {
        $dues = CustomerDue::query()
            ->with('customer:id,name,mobile')
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->where('status', CustomerDue::STATUS_OPEN)
            ->where('balance', '>', 0)
            ->get();

        $customerIds = $dues->pluck('customer_id')->unique()->filter()->values();

        $lastPayments = $customerIds->isEmpty()
            ? collect()
            : DuePayment::query()
                ->where('store_id', $store->id)
                ->whereIn('customer_id', $customerIds)
                ->select('customer_id', DB::raw('MAX(created_at) as last_payment_at'))
                ->groupBy('customer_id')
                ->pluck('last_payment_at', 'customer_id');

        $lastPurchases = $customerIds->isEmpty()
            ? collect()
            : Sale::query()
                ->where('store_id', $store->id)
                ->whereNull('deleted_at')
                ->whereIn('customer_id', $customerIds)
                ->select('customer_id', DB::raw('MAX(created_at) as last_purchase_at'))
                ->groupBy('customer_id')
                ->pluck('last_purchase_at', 'customer_id');

        $grouped = $dues->groupBy('customer_id')->map(function ($rows, $customerId) use ($lastPayments, $lastPurchases) {
            /** @var CustomerDue $first */
            $first = $rows->first();
            $lastPayment = $lastPayments->get($customerId);
            $lastPurchase = $lastPurchases->get($customerId);

            return [
                'customer_id' => (int) $customerId,
                'customer' => $first->customer?->name,
                'phone' => $first->customer?->mobile,
                'total_due' => round((float) $rows->sum('balance'), 2),
                'last_payment' => $lastPayment
                    ? Carbon::parse($lastPayment)->toDateString()
                    : null,
                'last_purchase_date' => $lastPurchase
                    ? Carbon::parse($lastPurchase)->toDateString()
                    : null,
            ];
        })->values()->sortByDesc('total_due')->values()->all();

        return [
            'currency' => self::CURRENCY,
            'from' => null,
            'to' => null,
            'as_of' => now()->toDateString(),
            'data' => $grouped,
        ];
    }

    public function slowMovingProducts(Store $store, Carbon $from, Carbon $to, int $limit): array
    {
        $saleItems = $this->table(SaleItem::class);
        $sales = $this->table(Sale::class);

        $sold = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $store->id)
            ->whereIn('sales.status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy('sale_items.product_id')
            ->select([
                'sale_items.product_id',
                DB::raw("SUM({$saleItems}.quantity) as quantity_sold"),
                DB::raw("MAX({$sales}.created_at) as last_sold_at"),
            ])
            ->get()
            ->keyBy('product_id');

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereNull('deleted_at')
            ->where('manage_inventory', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'stock_quantity', 'cost_price']);

        $data = $products->map(function (Product $product) use ($sold) {
            $row = $sold->get($product->id);
            $qty = round((float) ($row->quantity_sold ?? 0), 3);
            $stock = round((float) $product->stock_quantity, 3);
            $cost = round((float) ($product->cost_price ?? 0), 2);

            return [
                'product_id' => $product->id,
                'product' => $product->name,
                'sku' => $product->sku,
                'current_stock' => $stock,
                'quantity_sold' => $qty,
                'last_sold_date' => isset($row->last_sold_at)
                    ? Carbon::parse($row->last_sold_at)->toDateString()
                    : null,
                'stock_value' => round($stock * $cost, 2),
            ];
        })
            ->sortBy([
                ['quantity_sold', 'asc'],
                ['last_sold_date', 'asc'],
            ])
            ->take($limit)
            ->values()
            ->all();

        return [
            'currency' => self::CURRENCY,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $data,
        ];
    }

    /**
     * @return array{sale_count: int, gross_revenue: float, vat_total: float, discounts_total: float, returns_total: float, net_revenue: float, average_order_value: float, outstanding_dues: float}
     */
    private function salesSummaryMetrics(Store $store, Carbon $from, Carbon $to): array
    {
        $sales = $this->completedSalesQuery($store, $from, $to);

        $grossRevenue = (float) (clone $sales)->sum('total');
        $vatTotal = (float) (clone $sales)->sum('vat_total');
        $discountsTotal = (float) (clone $sales)->sum('discount_amount');
        $saleCount = (int) (clone $sales)->count();
        $returnsTotal = $this->returnsTotalInRange($store, $from, $to);
        $netRevenue = round($grossRevenue - $returnsTotal, 2);

        return [
            'sale_count' => $saleCount,
            'gross_revenue' => round($grossRevenue, 2),
            'vat_total' => round($vatTotal, 2),
            'discounts_total' => round($discountsTotal, 2),
            'returns_total' => round($returnsTotal, 2),
            'net_revenue' => $netRevenue,
            'average_order_value' => $saleCount > 0 ? round($netRevenue / $saleCount, 2) : 0.0,
            'outstanding_dues' => round($this->outstandingDues($store), 2),
        ];
    }

    /**
     * @return array{gross_revenue: float, returns_total: float, net_revenue: float, cogs: float, gross_profit: float, profit_margin_percent: float, expenses_total: float, net_profit: float}
     */
    private function profitSummaryMetrics(Store $store, Carbon $from, Carbon $to): array
    {
        $sales = $this->completedSalesQuery($store, $from, $to);

        $grossRevenue = (float) (clone $sales)->sum('total');
        $returnsTotal = $this->returnsTotalInRange($store, $from, $to);

        $cogs = (float) SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $store->id)
            ->whereIn('sales.status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(quantity * COALESCE(unit_cost, 0)), 0) as cogs')
            ->value('cogs');

        $expensesTotal = (float) Expense::query()
            ->where('store_id', $store->id)
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->sum('amount');

        $netRevenue = round($grossRevenue - $returnsTotal, 2);
        $cogs = round($cogs, 2);
        $grossProfit = round($netRevenue - $cogs, 2);
        $expensesTotal = round($expensesTotal, 2);
        $netProfit = round($grossProfit - $expensesTotal, 2);
        $margin = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0.0;

        return [
            'gross_revenue' => round($grossRevenue, 2),
            'returns_total' => round($returnsTotal, 2),
            'net_revenue' => $netRevenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'profit_margin_percent' => $margin,
            'expenses_total' => $expensesTotal,
            'net_profit' => $netProfit,
        ];
    }

    /**
     * Equal-length window immediately before the selected range.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previousPeriod(Carbon $from, Carbon $to): array
    {
        $days = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [$prevFrom, $prevTo];
    }

    private function changePercent(float|int $current, float|int $previous): ?float
    {
        $current = (float) $current;
        $previous = (float) $previous;

        if (abs($previous) < 0.0001) {
            if (abs($current) < 0.0001) {
                return 0.0;
            }

            // No prior baseline — treat any new activity as a full step up/down.
            return $current > 0 ? 100.0 : -100.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    private function completedSalesQuery(Store $store, Carbon $from, Carbon $to)
    {
        return Sale::query()
            ->where('store_id', $store->id)
            ->whereIn('status', [
                Sale::STATUS_COMPLETED,
                Sale::STATUS_PARTIALLY_RETURNED,
                Sale::STATUS_RETURNED,
            ])
            ->whereBetween('created_at', [$from, $to]);
    }

    private function returnsQuery(Store $store, Carbon $from, Carbon $to)
    {
        return SaleReturn::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$from, $to]);
    }

    private function returnsTotalInRange(Store $store, Carbon $from, Carbon $to): float
    {
        return (float) $this->returnsQuery($store, $from, $to)->sum('total');
    }

    private function outstandingDues(Store $store): float
    {
        return (float) CustomerDue::query()
            ->where('store_id', $store->id)
            ->where('status', CustomerDue::STATUS_OPEN)
            ->sum('balance');
    }

    /**
     * @return array<string, array{label: string, sale_count: int, gross_revenue: float, returns_total: float}>
     */
    private function initializeBuckets(Carbon $from, Carbon $to, string $period): array
    {
        $buckets = [];
        $cursor = $period === 'hour'
            ? $from->copy()->startOfHour()
            : $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $key = $this->bucketKey($cursor, $period);
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'label' => $this->bucketLabel($cursor, $period),
                    'sale_count' => 0,
                    'gross_revenue' => 0.0,
                    'returns_total' => 0.0,
                ];
            }

            $cursor = match ($period) {
                'hour' => $cursor->addHour(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    private function bucketKey(Carbon $date, string $period): string
    {
        return match ($period) {
            'hour' => $date->format('Y-m-d H:00'),
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function bucketLabel(Carbon $date, string $period): string
    {
        return match ($period) {
            'hour' => $date->format('H:00'),
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }
}
