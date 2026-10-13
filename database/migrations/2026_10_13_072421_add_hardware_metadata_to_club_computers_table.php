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
        Schema::table('club_computers', function (Blueprint $table) {
            $table->string('mac_address', 17)->nullable();
            $table->string('local_ip', 45)->nullable();
            $table->string('remote_ip', 45)->nullable();
            $table->string('cpu_model', 255)->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->unsignedInteger('ram_total_mb')->nullable();
            $table->string('gpu_model', 255)->nullable();
            $table->string('os_version', 255)->nullable();
            $table->timestamp('hardware_reported_at')->nullable();
            $table->index(['club_id', 'mac_address'], 'club_computers_club_mac_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->dropIndex('club_computers_club_mac_index');
            $table->dropColumn([
                'mac_address', 'local_ip', 'remote_ip', 'cpu_model', 'cpu_cores',
                'ram_total_mb', 'gpu_model', 'os_version', 'hardware_reported_at',
            ]);
        });
    }
};
