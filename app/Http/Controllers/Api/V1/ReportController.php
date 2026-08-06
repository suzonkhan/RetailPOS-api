<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DailySalesReportRequest;
use App\Http\Requests\Reports\ReportDateRangeRequest;
use App\Http\Requests\Reports\SalesTrendReportRequest;
use App\Http\Requests\Reports\SlowMovingProductsReportRequest;
use App\Http\Requests\Reports\TopProductsReportRequest;
use App\Services\Reports\ReportScopeService;
use App\Services\Reports\ReportService;
use App\Support\AppTimezone;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportScopeService $scope,
        private readonly ReportService $reports,
    ) {}

    public function salesSummary(ReportDateRangeRequest $request): JsonResponse
    {
        $range = $this->scope->resolveDateRange($request->validated());
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->salesSummary($store, $range['from'], $range['to'])
        );
    }

    public function salesTrend(SalesTrendReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $range = $this->scope->resolveDateRange($validated);
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->salesTrend(
                $store,
                $range['from'],
                $range['to'],
                $validated['period'],
            )
        );
    }

    public function topProducts(TopProductsReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $range = $this->scope->resolveDateRange($validated);
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->topProducts(
                $store,
                $range['from'],
                $range['to'],
                (int) $validated['limit'],
                $validated['sort_by'],
            )
        );
    }

    public function paymentBreakdown(ReportDateRangeRequest $request): JsonResponse
    {
        $range = $this->scope->resolveDateRange($request->validated());
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->paymentBreakdown($store, $range['from'], $range['to'])
        );
    }

    public function profitSummary(ReportDateRangeRequest $request): JsonResponse
    {
        $range = $this->scope->resolveDateRange($request->validated());
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->profitSummary($store, $range['from'], $range['to'])
        );
    }

    public function businessSummary(ReportDateRangeRequest $request): JsonResponse
    {
        $range = $this->scope->resolveDateRange($request->validated());
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->businessSummary($store, $range['from'], $range['to'])
        );
    }

    public function dailySales(DailySalesReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $store = $this->scope->storeFor($request->user());

        if (isset($validated['date'])) {
            $from = AppTimezone::startOfDay($validated['date']);
            $to = AppTimezone::endOfDay($validated['date']);
        } else {
            $range = $this->scope->resolveDateRange($validated);
            $from = $range['from'];
            $to = $range['to'];
        }

        return response()->json(
            $this->reports->dailySales($store, $from, $to)
        );
    }

    public function productSales(ReportDateRangeRequest $request): JsonResponse
    {
        $range = $this->scope->resolveDateRange($request->validated());
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->productSales($store, $range['from'], $range['to'])
        );
    }

    public function currentStock(): JsonResponse
    {
        $store = $this->scope->storeFor(request()->user());

        return response()->json(
            $this->reports->currentStock($store)
        );
    }

    public function stockLedger(ReportDateRangeRequest $request): JsonResponse
    {
        $range = $this->scope->resolveDateRange($request->validated());
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->stockLedger($store, $range['from'], $range['to'])
        );
    }

    public function lowStock(): JsonResponse
    {
        $store = $this->scope->storeFor(request()->user());

        return response()->json(
            $this->reports->lowStock($store)
        );
    }

    public function expenses(ReportDateRangeRequest $request): JsonResponse
    {
        $range = $this->scope->resolveDateRange($request->validated());
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->expensesReport($store, $range['from'], $range['to'])
        );
    }

    public function customerDues(): JsonResponse
    {
        $store = $this->scope->storeFor(request()->user());

        return response()->json(
            $this->reports->customerDues($store)
        );
    }

    public function slowMovingProducts(SlowMovingProductsReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $range = $this->scope->resolveDateRange($validated);
        $store = $this->scope->storeFor($request->user());

        return response()->json(
            $this->reports->slowMovingProducts(
                $store,
                $range['from'],
                $range['to'],
                (int) $validated['limit'],
            )
        );
    }
}
