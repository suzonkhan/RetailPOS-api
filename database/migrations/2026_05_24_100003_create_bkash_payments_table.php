<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bkash_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('payment_id')->nullable()->unique();
            $table->string('trx_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('status')->default('created');
            $table->string('transaction_status')->nullable();
            $table->json('create_response')->nullable();
            $table->json('execute_response')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bkash_payments');
    }
};
