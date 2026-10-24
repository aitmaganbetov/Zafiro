<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When a tariff is on sale: before the start or after the end it is not shown or sold. Null — no limit. */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->timestamp('sale_starts_at')->nullable()->after('validity_periods');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn(['sale_starts_at', 'sale_ends_at']);
        });
    }
};
