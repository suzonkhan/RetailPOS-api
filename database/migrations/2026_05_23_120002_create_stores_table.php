<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('status')->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('data_purge_scheduled_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
    }
};
