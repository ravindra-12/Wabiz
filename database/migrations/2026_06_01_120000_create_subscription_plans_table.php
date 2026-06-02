<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the subscription_plans table that stores
     * available SaaS plans with pricing, limits, and features.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();

            $table->string('name');                    // e.g. Free, Pro, Enterprise
            $table->string('slug')->unique();          // e.g. free, pro, enterprise
            $table->text('description')->nullable();

            // Pricing
            $table->decimal('price', 10, 2)->default(0.00);
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');

            // Usage limits
            $table->integer('max_leads')->default(50);
            $table->integer('max_messages')->default(100);
            $table->integer('max_orders')->default(10);

            // Additional features (JSON array)
            $table->json('features')->nullable();

            // Plan status
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
