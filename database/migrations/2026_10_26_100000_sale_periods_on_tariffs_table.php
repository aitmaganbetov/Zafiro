<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sale time of a tariff is days and times of the week, like its validity period
     * (it replaces the start and end dates, which no tariff had set). Null — always on sale.
     */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->json('sale_periods')->nullable()->after('validity_periods');
        });
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn(['sale_starts_at', 'sale_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->timestamp('sale_starts_at')->nullable()->after('validity_periods');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
        });
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn('sale_periods');
        });
    }
};
