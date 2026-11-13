<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computer_reservations', function (Blueprint $table) {
            // A booking from the guest app is paid from the balances: the time starts when the guest
            // signs in on the PC; a cancelled booking gives the money back (ComputerReservations).
            $table->boolean('from_app')->default(false)->after('note');
            $table->unsignedInteger('paid_main_cents')->default(0)->after('from_app');
            $table->unsignedInteger('paid_bonus_cents')->default(0)->after('paid_main_cents');
            // The guest's bookings in the app.
            $table->index(['club_id', 'user_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('computer_reservations', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'user_id', 'starts_at']);
            $table->dropColumn(['from_app', 'paid_main_cents', 'paid_bonus_cents']);
        });
    }
};
