<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds user_id to leads table for multi-tenant data isolation.
     * Each lead now belongs to the user (tenant) who owns the WhatsApp account.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('user_id')
                  ->nullable()
                  ->after('id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Composite index: user_id + phone must be unique per tenant
            $table->unique(['user_id', 'phone'], 'leads_user_phone_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique('leads_user_phone_unique');
            $table->dropColumn('user_id');
        });
    }
};
