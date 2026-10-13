<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach ([
            'products' => 'products_club_id_id_unique',
            'product_categories' => 'product_categories_club_id_id_unique',
            'cash_shifts' => 'cash_shifts_club_id_id_unique',
            'club_zones' => 'club_zones_club_id_id_unique',
            'club_computers' => 'club_computers_club_id_id_unique',
            'tariffs' => 'tariffs_club_id_id_unique',
        ] as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->unique(['club_id', 'id'], $indexName);
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_product_category_id_foreign');
            $table->foreign(['club_id', 'product_category_id'], 'products_club_category_fk')
                ->references(['club_id', 'id'])->on('product_categories')->restrictOnDelete();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign('stock_movements_product_id_foreign');
            $table->dropForeign('stock_movements_user_id_foreign');
            $table->foreign(['club_id', 'product_id'], 'stock_movements_club_product_fk')
                ->references(['club_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['club_id', 'user_id'], 'stock_movements_club_user_fk')
                ->references(['club_id', 'user_id'])->on('club_memberships');
        });
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->dropForeign('cash_shifts_opened_by_foreign');
            $table->dropForeign('cash_shifts_closed_by_foreign');
            $table->foreign(['club_id', 'opened_by'], 'cash_shifts_opened_by_membership_fk')
                ->references(['club_id', 'user_id'])->on('club_memberships');
            $table->foreign(['club_id', 'closed_by'], 'cash_shifts_closed_by_membership_fk')
                ->references(['club_id', 'user_id'])->on('club_memberships');
        });
        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->dropForeign('cash_transactions_cash_shift_id_foreign');
            $table->dropForeign('cash_transactions_user_id_foreign');
            $table->foreign(['club_id', 'cash_shift_id'], 'cash_transactions_club_shift_fk')
                ->references(['club_id', 'id'])->on('cash_shifts')->cascadeOnDelete();
            $table->foreign(['club_id', 'user_id'], 'cash_transactions_club_user_fk')
                ->references(['club_id', 'user_id'])->on('club_memberships');
        });
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->dropForeign('club_computers_club_zone_id_foreign');
            $table->foreign(['club_id', 'club_zone_id'], 'club_computers_club_zone_fk')
                ->references(['club_id', 'id'])->on('club_zones')->cascadeOnDelete();
        });
        Schema::table('club_zone_tariff', function (Blueprint $table): void {
            $table->dropForeign('club_zone_tariff_club_zone_id_foreign');
            $table->dropForeign('club_zone_tariff_tariff_id_foreign');
            $table->foreign(['club_id', 'club_zone_id'], 'club_zone_tariff_club_zone_fk')
                ->references(['club_id', 'id'])->on('club_zones')->cascadeOnDelete();
            $table->foreign(['club_id', 'tariff_id'], 'club_zone_tariff_tariff_fk')
                ->references(['club_id', 'id'])->on('tariffs')->cascadeOnDelete();
        });
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->dropForeign('activity_logs_user_id_foreign');
                $table->foreign(['club_id', 'user_id'], 'activity_logs_club_user_fk')
                ->references(['club_id', 'user_id'])->on('club_memberships');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('club_zone_tariff', function (Blueprint $table): void {
            $table->dropForeign('club_zone_tariff_club_zone_fk');
            $table->dropForeign('club_zone_tariff_tariff_fk');
            $table->foreign('club_zone_id')->references('id')->on('club_zones')->cascadeOnDelete();
            $table->foreign('tariff_id')->references('id')->on('tariffs')->cascadeOnDelete();
        });
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->dropForeign('club_computers_club_zone_fk');
            $table->foreign('club_zone_id')->references('id')->on('club_zones')->cascadeOnDelete();
        });
        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->dropForeign('cash_transactions_club_shift_fk');
            $table->dropForeign('cash_transactions_club_user_fk');
            $table->foreign('cash_shift_id')->references('id')->on('cash_shifts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->dropForeign('cash_shifts_opened_by_membership_fk');
            $table->dropForeign('cash_shifts_closed_by_membership_fk');
            $table->foreign('opened_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign('stock_movements_club_product_fk');
            $table->dropForeign('stock_movements_club_user_fk');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_club_category_fk');
            $table->foreign('product_category_id')->references('id')->on('product_categories')->nullOnDelete();
        });
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropForeign('activity_logs_club_user_fk');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        foreach ([
            'products_club_id_id_unique' => 'products',
            'product_categories_club_id_id_unique' => 'product_categories',
            'cash_shifts_club_id_id_unique' => 'cash_shifts',
            'club_zones_club_id_id_unique' => 'club_zones',
            'club_computers_club_id_id_unique' => 'club_computers',
            'tariffs_club_id_id_unique' => 'tariffs',
        ] as $indexName => $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        }
    }
};
