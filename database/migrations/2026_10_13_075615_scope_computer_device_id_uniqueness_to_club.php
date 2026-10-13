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
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->dropUnique('club_computers_device_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->unique('device_id', 'club_computers_device_id_unique');
        });
    }
};
