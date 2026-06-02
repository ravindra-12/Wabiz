<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the payment_transactions table that records
     * every payment event for audit and reconciliation.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('subscription_id')
                  ->nullable()
                  ->constrained('user_subscriptions')
                  ->nullOnDelete();

            // Payment gateway info
            $table->string('gateway');                       // razorpay, stripe
            $table->string('transaction_id')->nullable();    // gateway transaction ID
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('INR');

            // Transaction status
            $table->enum('status', ['success', 'failed', 'pending', 'refunded'])
                  ->default('pending');

            // Raw gateway response for audit
            $table->json('response_payload')->nullable();

            $table->timestamps();

            // Indexes for fast lookups
            $table->index(['user_id', 'status']);
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
