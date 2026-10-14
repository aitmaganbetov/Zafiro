<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Club logo shown on the locked PC monitors. The file name is random, so a new upload gets a new URL.
     */
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
