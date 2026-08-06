<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('purchase_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('purchases')
                ->nullOnDelete();

            $table->index(['purchase_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });
    }
};
