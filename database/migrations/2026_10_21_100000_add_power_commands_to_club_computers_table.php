<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff turn PCs off and restart them from the panel: the command waits here until the
     * agent takes it with its heartbeat. A PC to turn on is woken (Wake-on-LAN) by another
     * PC of the club, which finds it by wake_requested_at.
     */
    public function up(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->string('power_command', 16)->nullable()->after('status');
            $table->timestamp('power_command_at')->nullable()->after('power_command');
            $table->timestamp('wake_requested_at')->nullable()->after('power_command_at');
            $table->index(['club_id', 'wake_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'wake_requested_at']);
            $table->dropColumn(['power_command', 'power_command_at', 'wake_requested_at']);
        });
    }
};
