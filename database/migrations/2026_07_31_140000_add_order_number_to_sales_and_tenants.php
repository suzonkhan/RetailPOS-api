<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_order_number')->default(0)->after('billing_cycle');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('order_number')->default(0)->after('client_uuid');
        });

        $tenantIds = DB::table('sales')->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $saleIds = DB::table('sales')
                ->where('tenant_id', $tenantId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            $number = 0;
            foreach ($saleIds as $saleId) {
                $number++;
                DB::table('sales')->where('id', $saleId)->update(['order_number' => $number]);
            }

            DB::table('tenants')->where('id', $tenantId)->update(['last_order_number' => $number]);
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->unique(['tenant_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'order_number']);
            $table->dropColumn('order_number');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('last_order_number');
        });
    }
};
