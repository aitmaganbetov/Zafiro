<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A tariff no longer has a color and a club name: nothing showed them (the club is the tenant). */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn(['color', 'club_name']);
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->string('color', 7)->default('#ef4444');
            $table->string('club_name')->nullable();
        });
    }
};
