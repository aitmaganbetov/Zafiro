<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computer_reservations', function (Blueprint $table) {
            // A booking paid as a tariff (not by the hour): its time starts as that tariff and
            // counts towards the tariff's purchase limit.
            $table->foreignId('tariff_id')->nullable()->after('paid_bonus_cents')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('computer_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tariff_id');
        });
    }
};
