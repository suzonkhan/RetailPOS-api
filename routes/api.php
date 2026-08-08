<?php

use App\Http\Controllers\Api\V1\Auth\ChangePinController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SetDefaultBranchController;
use App\Http\Controllers\Api\V1\BkashWebhookController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BranchSubscriptionController;
use App\Http\Controllers\Api\V1\BranchUsageController;
use App\Http\Controllers\Api\V1\CheckoutBkashController;
use App\Http\Controllers\Api\V1\CheckoutQuoteController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DuePaymentController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\ProductBarcodeLookupController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductCsvController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\VariationAttributeController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\TenantSubscriptionController;
use App\Http\Controllers\Api\V1\TenantUsageController;
use App\Http\Controllers\Api\V1\Platform\PlatformBranchController;
use App\Http\Controllers\Api\V1\Platform\PlatformCouponController;
use App\Http\Controllers\Api\V1\Platform\PlatformPlanController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantBillingController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
        ]);
    });

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/bkash/webhook', BkashWebhookController::class);

    Route::prefix('auth')->group(function () {
        Route::post('/register', RegisterController::class);
        Route::post('/login', LoginController::class);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', LogoutController::class);
            Route::get('/me', MeController::class);
            Route::post('/pin/change', ChangePinController::class);
            Route::patch('/me/default-branch', SetDefaultBranchController::class);
        });
    });

    Route::middleware(['auth:sanctum', 'tenant.member'])->group(function () {
        Route::get('/tenant/branches', [BranchController::class, 'index']);
        Route::get('/tenant/branches/{branch}', [BranchController::class, 'show']);
        Route::patch('/tenant/branches/{branch}', [BranchController::class, 'update']);
        Route::get('/tenant/branches/{branch}/subscription', [BranchSubscriptionController::class, 'show']);
        Route::get('/tenant/branches/{branch}/usage', [BranchUsageController::class, 'show']);

        Route::middleware('permission:subscription.manage')->group(function () {
            Route::post('/checkout/quote', CheckoutQuoteController::class);
            Route::post('/checkout/bkash/create', [CheckoutBkashController::class, 'create']);
            Route::post('/checkout/bkash/execute', [CheckoutBkashController::class, 'execute']);
            Route::get('/tenant/subscription', [TenantSubscriptionController::class, 'show']);
            Route::get('/tenant/usage', [TenantUsageController::class, 'show']);
        });
    });

    Route::middleware(['auth:sanctum', 'tenant.member', 'subscription.active'])->group(function () {
        Route::middleware('permission:users.manage')->group(function () {
            Route::apiResource('users', UserController::class);
            Route::post('/staff/{staff}/enable-login', [StaffController::class, 'enableLogin']);
        });

        Route::middleware('permission:settings.view')->group(function () {
            Route::get('/tenant/settings', [TenantSettingsController::class, 'show']);
            Route::put('/tenant/settings', [TenantSettingsController::class, 'update']);
        });

        Route::middleware('permission:pos.use')->group(function () {
            Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        });

        Route::middleware('permission:settings.payment_methods')->group(function () {
            Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
            Route::get('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'show']);
            Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
            Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
        });

        Route::middleware('permission:catalog.manage')->group(function () {
            Route::apiResource('categories', CategoryController::class);
            Route::apiResource('suppliers', SupplierController::class);
            Route::apiResource('brands', BrandController::class);
            Route::apiResource('variation-attributes', VariationAttributeController::class);
            Route::post('variation-attributes/{variation_attribute}/values', [VariationAttributeController::class, 'storeValue']);
            Route::put('variation-attribute-values/{variationAttributeValue}', [VariationAttributeController::class, 'updateValue']);
            Route::delete('variation-attribute-values/{variationAttributeValue}', [VariationAttributeController::class, 'destroyValue']);
            Route::get('products/export', [ProductCsvController::class, 'export']);
            Route::get('products/import/template', [ProductCsvController::class, 'template']);
            Route::post('products/import', [ProductCsvController::class, 'import']);
            Route::get('products/barcode-lookup', ProductBarcodeLookupController::class);
            Route::apiResource('products', ProductController::class);
            Route::get('products/{product}/variations', [ProductVariantController::class, 'show']);
            Route::put('products/{product}/variations/setup', [ProductVariantController::class, 'setup']);
            Route::put('products/{product}/variants/bulk', [ProductVariantController::class, 'bulkUpdate']);
            Route::put('products/{product}/variants/{variant}', [ProductVariantController::class, 'update']);
            Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy']);
            Route::get('products/{product}/images', [ProductImageController::class, 'index']);
            Route::post('products/{product}/images', [ProductImageController::class, 'store']);
            Route::delete('products/{product}/images/{productImage}', [ProductImageController::class, 'destroy']);
        });

        Route::middleware('permission:customers.manage')->group(function () {
            Route::apiResource('customers', CustomerController::class);
        });

        Route::middleware('permission:purchases.manage')->group(function () {
            Route::get('/purchases', [PurchaseController::class, 'index']);
            Route::post('/purchases', [PurchaseController::class, 'store']);
            Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
            Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy']);
            Route::get('/inventory/lots', [InventoryController::class, 'lots']);
            Route::post('/stock-adjustments', [InventoryController::class, 'adjust']);
        });

        Route::middleware('permission:expenses.manage')->group(function () {
            Route::apiResource('expense-categories', ExpenseCategoryController::class);
            Route::apiResource('expenses', ExpenseController::class);
        });

        Route::middleware('permission:staff.manage')->group(function () {
            Route::get('/staff', [StaffController::class, 'index']);
            Route::post('/staff', [StaffController::class, 'store']);
            Route::get('/staff/{staff}', [StaffController::class, 'show']);
            Route::put('/staff/{staff}', [StaffController::class, 'update']);
            Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
            Route::get('/staff/{staff}/payments', [StaffController::class, 'payments']);
            Route::post('/staff/{staff}/payments', [StaffController::class, 'storePayment']);
        });

        Route::middleware('permission:pos.use')->group(function () {
            Route::post('/devices', [DeviceController::class, 'store']);
            Route::get('/sync/pull', [SyncController::class, 'pull']);
            Route::post('/sync/push', [SyncController::class, 'push']);
            Route::get('/sales', [SaleController::class, 'index']);
            Route::post('/sales', [SaleController::class, 'store']);
            Route::get('/sales/{sale}', [SaleController::class, 'show']);
            Route::post('/sales/{sale}/returns', [SaleReturnController::class, 'store']);
            Route::post('/customers/{customer}/due-payments', [DuePaymentController::class, 'store']);
        });

        Route::middleware('permission:reports.view|dashboard.view')->group(function () {
            Route::get('/reports/sales-summary', [ReportController::class, 'salesSummary']);
            Route::get('/reports/sales-trend', [ReportController::class, 'salesTrend']);
            Route::get('/reports/top-products', [ReportController::class, 'topProducts']);
            Route::get('/reports/payment-breakdown', [ReportController::class, 'paymentBreakdown']);
            Route::get('/reports/profit-summary', [ReportController::class, 'profitSummary']);
            Route::get('/reports/business-summary', [ReportController::class, 'businessSummary']);
            Route::get('/reports/daily-sales', [ReportController::class, 'dailySales']);
            Route::get('/reports/product-sales', [ReportController::class, 'productSales']);
            Route::get('/reports/current-stock', [ReportController::class, 'currentStock']);
            Route::get('/reports/stock-ledger', [ReportController::class, 'stockLedger']);
            Route::get('/reports/low-stock', [ReportController::class, 'lowStock']);
            Route::get('/reports/expenses', [ReportController::class, 'expenses']);
            Route::get('/reports/customer-dues', [ReportController::class, 'customerDues']);
            Route::get('/reports/slow-moving-products', [ReportController::class, 'slowMovingProducts']);
        });
    });

    Route::middleware(['auth:sanctum', 'platform.admin'])->prefix('platform')->group(function () {
        Route::middleware('permission:platform.tenants')->group(function () {
            Route::get('/branches', [PlatformBranchController::class, 'index']);
            Route::get('/tenants', [PlatformTenantController::class, 'index']);
            Route::post('/tenants', [PlatformTenantController::class, 'store']);
            Route::get('/tenants/{tenant}', [PlatformTenantController::class, 'show']);
            Route::patch('/tenants/{tenant}', [PlatformTenantController::class, 'update']);
            Route::patch('/tenants/{tenant}/owner-pin', [PlatformTenantController::class, 'resetOwnerPin']);
            Route::get('/tenants/{tenant}/billing', [PlatformTenantBillingController::class, 'index']);
            Route::post('/tenants/{tenant}/billing/{invoice}/approve', [PlatformTenantBillingController::class, 'approve']);
        });

        Route::middleware('permission:platform.plans')->group(function () {
            Route::get('/plans', [PlatformPlanController::class, 'index']);
            Route::post('/plans', [PlatformPlanController::class, 'store']);
            Route::get('/plans/{plan}', [PlatformPlanController::class, 'show']);
            Route::patch('/plans/{plan}', [PlatformPlanController::class, 'update']);
        });

        Route::middleware('permission:platform.coupons')->group(function () {
            Route::get('/coupons', [PlatformCouponController::class, 'index']);
            Route::post('/coupons', [PlatformCouponController::class, 'store']);
            Route::patch('/coupons/{coupon}', [PlatformCouponController::class, 'update']);
        });
    });
});
