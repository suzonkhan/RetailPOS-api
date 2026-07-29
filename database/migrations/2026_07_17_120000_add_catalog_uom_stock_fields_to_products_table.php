<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('min_stock_quantity', 12, 3)->nullable()->after('stock_quantity');
            $table->date('expiration_date')->nullable()->after('min_stock_quantity');
            $table->string('uom', 16)->default('pcs')->after('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['min_stock_quantity', 'expiration_date', 'uom']);
        });
    }
};
