<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The club's picture on the sign-in screen of the PC agent (public by its random file name).
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('signin_image_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('signin_image_path');
        });
    }
};
