<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drops the old key-value settings table and creates a proper
     * per-user business settings table for multi-tenant SaaS.
     */
    public function up(): void
    {
        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->unique()
                  ->constrained()
                  ->cascadeOnDelete();

            // Business profile
            $table->string('business_name')->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('business_logo')->nullable();

            // Regional
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('default_country_code', 5)->default('+91');

            // Automation toggles
            $table->boolean('auto_followup_enabled')->default(true);
            $table->boolean('welcome_message_enabled')->default(true);
            $table->boolean('order_notification_enabled')->default(true);

            // Default templates
            $table->foreignId('default_welcome_template_id')
                  ->nullable()
                  ->constrained('templates')
                  ->nullOnDelete();

            $table->foreignId('default_followup_template_id')
                  ->nullable()
                  ->constrained('templates')
                  ->nullOnDelete();

            $table->foreignId('default_order_template_id')
                  ->nullable()
                  ->constrained('templates')
                  ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');

        // Restore original key-value settings table
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
};
