<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_attributes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'updated_at']);
            $table->index(['store_id', 'sort_order']);
        });

        Schema::create('variation_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('variation_attribute_id')
                ->constrained(indexName: 'var_attr_vals_attr_id_fk')
                ->cascadeOnDelete();
            $table->string('value');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['variation_attribute_id', 'sort_order'], 'var_attr_vals_attr_sort_idx');
        });

        Schema::create('product_variation_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_attribute_id')
                ->constrained(indexName: 'prod_var_attrs_attr_id_fk')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'variation_attribute_id'], 'prod_var_attrs_unique');
        });

        Schema::create('product_variation_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_attribute_value_id')
                ->constrained(indexName: 'prod_var_vals_val_id_fk')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'variation_attribute_value_id'], 'product_variation_values_unique');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('selling_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('stock_quantity', 12, 3)->default(0);
            $table->string('option_signature');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'option_signature']);
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'updated_at']);
            $table->index(['store_id', 'barcode']);
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('product_variant_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_attribute_value_id')
                ->constrained(indexName: 'prod_var_opt_val_id_fk')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['product_variant_id', 'variation_attribute_value_id'],
                'product_variant_options_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_options');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_variation_values');
        Schema::dropIfExists('product_variation_attributes');
        Schema::dropIfExists('variation_attribute_values');
        Schema::dropIfExists('variation_attributes');
    }
};
