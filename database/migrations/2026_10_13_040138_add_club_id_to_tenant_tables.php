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
                $table->unsignedBigInteger('club_id')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'club_zone_tariff', 'activity_logs', 'tariffs', 'club_computers', 'club_zones', 'cash_transactions',
            'cash_shifts', 'stock_movements', 'product_categories', 'products',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('club_id');
            });
        }
    }
};
