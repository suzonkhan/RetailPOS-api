<?php

use App\Models\Store;
use App\Services\Catalog\UomCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_uoms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('label', 64);
            $table->boolean('fractional')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'code']);
            $table->index(['store_id', 'is_active']);
        });

        Schema::create('store_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_uom_id')->constrained('store_uoms')->cascadeOnDelete();
            $table->foreignId('to_uom_id')->constrained('store_uoms')->cascadeOnDelete();
            $table->decimal('factor', 16, 6);
            $table->timestamps();

            $table->unique(['store_id', 'from_uom_id', 'to_uom_id'], 'store_uom_conv_pair_unique');
        });

        /** @var UomCatalogService $uoms */
        $uoms = app(UomCatalogService::class);

        Store::query()->orderBy('id')->each(function (Store $store) use ($uoms): void {
            $uoms->seedDefaults($store);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_uom_conversions');
        Schema::dropIfExists('store_uoms');
    }
};
