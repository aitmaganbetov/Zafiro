<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price of an hour in the zone: customers buy any number of minutes at this rate in the agent.
        Schema::table('club_zones', function (Blueprint $table) {
            $table->decimal('price_per_hour', 12, 2)->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('club_zones', function (Blueprint $table) {
            $table->dropColumn('price_per_hour');
        });
    }
};
