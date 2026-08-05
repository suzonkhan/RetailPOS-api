<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('monthly_price');
            $table->unsignedInteger('yearly_price');
            $table->unsignedSmallInteger('max_users');
            $table->unsignedSmallInteger('max_stores')->default(1);
            $table->unsignedSmallInteger('max_categories');
            $table->unsignedInteger('max_products');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_trial_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
