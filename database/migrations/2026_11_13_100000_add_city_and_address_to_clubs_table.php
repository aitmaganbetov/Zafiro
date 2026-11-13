<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // App\Support\Cities key: the guest app lists the clubs of a city.
            $table->string('city', 32)->nullable()->index();
            $table->string('address', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropColumn(['city', 'address']);
        });
    }
};
