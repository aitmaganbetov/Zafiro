<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each club shows times in its own time zone; stored times stay in UTC.
     * Existing clubs are in Kazakhstan (one zone, UTC+5, since 2024).
     */
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('Asia/Almaty');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
