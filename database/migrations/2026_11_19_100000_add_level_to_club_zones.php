<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The zone's level, set by the club: a customer with a tariff moves to zones of the same or a lower level.
        Schema::table('club_zones', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->nullable()->after('price_per_hour');
        });
    }

    public function down(): void
    {
        Schema::table('club_zones', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
