<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purchase_number');
            $table->timestamp('purchased_at');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'purchase_number']);
            $table->index(['tenant_id', 'updated_at']);
            $table->index(['store_id', 'purchased_at']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->string('variant_label')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->date('expiration_date')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->date('expiration_date')->nullable();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_remaining', 12, 3);
            $table->timestamps();

            $table->index(['store_id', 'product_id', 'received_at']);
            $table->index(['product_id', 'quantity_remaining']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity_delta', 12, 3);
            $table->string('reason')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->date('expiration_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'updated_at']);
            $table->index(['store_id', 'created_at']);
        });

        Schema::create('sale_item_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();

            $table->index(['sale_item_id']);
            $table->index(['stock_lot_id']);
        });

        $products = DB::table('products')->where('stock_quantity', '>', 0)->get();

        foreach ($products as $product) {
            DB::table('stock_lots')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $product->tenant_id,
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'purchase_item_id' => null,
                'received_at' => $product->created_at ?? now(),
                'expiration_date' => $product->expiration_date,
                'unit_cost' => $product->cost_price ?? 0,
                'quantity_received' => $product->stock_quantity,
                'quantity_remaining' => $product->stock_quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_lot_allocations');

        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
