<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A deleted tariff is gone from sale but kept for history and the passes bought with it. */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
