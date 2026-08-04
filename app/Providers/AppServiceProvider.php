<?php

namespace App\Providers;

use App\Contracts\BkashGateway;
use App\Models\Store;
use App\Services\Bkash\MockBkashGateway;
use App\Services\Bkash\SandboxBkashGateway;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BkashGateway::class, function () {
            $appKey = config('retail360.bkash.app_key');

            if (empty($appKey)) {
                return new MockBkashGateway;
            }

            return new SandboxBkashGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        JsonResource::withoutWrapping();

        Route::bind('branch', function (string $value) {
            return Store::query()->findOrFail($value);
        });
    }
}
