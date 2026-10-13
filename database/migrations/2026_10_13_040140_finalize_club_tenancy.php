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
        foreach ([
            'products', 'product_categories', 'stock_movements', 'cash_shifts',
            'cash_transactions', 'club_zones', 'club_computers', 'tariffs', 'activity_logs', 'club_zone_tariff',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('club_id')->nullable(false)->change();
                $table->foreign('club_id')->references('id')->on('clubs')->cascadeOnDelete();
                $table->index('club_id');
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_sku_unique');
            $table->unique(['club_id', 'sku']);
        });
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropUnique('product_categories_name_unique');
            $table->unique(['club_id', 'name']);
        });
        Schema::table('tariffs', function (Blueprint $table): void {
            $table->dropUnique('tariffs_name_unique');
            $table->unique(['club_id', 'name']);
        });
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->dropUnique('cash_shifts_open_marker_unique');
            $table->unique(['club_id', 'open_marker']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'discount', 'balance', 'bonus_balance']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Club tenancy cannot be rolled back without merging tenant data.');
    }
};
