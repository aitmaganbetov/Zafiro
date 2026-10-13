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
        Schema::table('clubs', function (Blueprint $table) {
            $table->char('installation_key_hash', 64)->nullable()->unique();
            $table->char('installation_key_prefix', 12)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropUnique(['installation_key_hash']);
            $table->dropIndex(['installation_key_prefix']);
            $table->dropColumn(['installation_key_hash', 'installation_key_prefix']);
        });
    }
};
