<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->string('payment_method', 32)->default('main_balance_only');
            $table->string('currency', 10)->default('KZT');
            $table->boolean('discount_enabled')->default(false);
            $table->string('color', 7)->default('#ef4444');
            $table->string('club_name')->nullable();
            $table->json('user_roles')->nullable();
            $table->boolean('usage_limit_enabled')->default(false);
            $table->unsignedInteger('usage_limit_minutes')->nullable();
            $table->boolean('quantity_limit_enabled')->default(false);
            $table->unsignedInteger('quantity_limit')->nullable();
            $table->json('validity_periods')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'currency',
                'discount_enabled',
                'color',
                'club_name',
                'user_roles',
                'usage_limit_enabled',
                'usage_limit_minutes',
                'quantity_limit_enabled',
                'quantity_limit',
                'validity_periods',
            ]);
        });
    }
};
