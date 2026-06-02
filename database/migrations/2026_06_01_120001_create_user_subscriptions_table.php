<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the user_subscriptions table that tracks each
     * user's subscription lifecycle (subscribe → renew → expire/cancel).
     */
    public function up(): void
    {
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('subscription_plan_id')
                  ->constrained('subscription_plans')
                  ->cascadeOnDelete();

            // Payment info
            $table->string('payment_gateway')->nullable();   // razorpay, stripe, manual
            $table->string('payment_id')->nullable();        // gateway payment/order ID
            $table->decimal('amount', 10, 2)->default(0.00);

            // Subscription period
            $table->timestamp('start_date')->nullable();
            $table->timestamp('expiry_date')->nullable();

            // Status
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])
                  ->default('pending');

            $table->timestamps();

            // Index for fast lookups
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
};
