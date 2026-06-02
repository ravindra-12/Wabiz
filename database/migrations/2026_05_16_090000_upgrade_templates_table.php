<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Upgrades the basic templates table to a full multi-tenant
     * template management system with categories, variables, and slugs.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('user_id')
                  ->nullable()
                  ->after('id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('slug')->unique()->after('name');

            $table->enum('category', [
                'welcome',
                'followup',
                'order',
                'marketing',
                'support',
                'custom'
            ])->default('custom')->after('slug');

            $table->json('variables')->nullable()->after('content');

            $table->enum('status', ['active', 'inactive'])
                  ->default('active')
                  ->after('variables');

            // Composite index for fast tenant + category lookups
            $table->index(['user_id', 'category']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'category']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn(['user_id', 'slug', 'category', 'variables', 'status']);
        });
    }
};
