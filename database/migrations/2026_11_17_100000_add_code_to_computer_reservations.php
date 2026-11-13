<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computer_reservations', function (Blueprint $table) {
            // One code for the PCs a guest booked together in the app: entered on the sign-in screen
            // of one of those PCs, it starts the paid time there without an account.
            $table->string('code', 8)->nullable()->after('tariff_id');
            $table->index(['club_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('computer_reservations', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
