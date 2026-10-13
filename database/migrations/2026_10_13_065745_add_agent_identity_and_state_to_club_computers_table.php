<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->string('device_name', 255)->nullable();
            $table->string('device_id', 255)->nullable();
            $table->string('agent_state', 20)->nullable();
            $table->string('agent_version', 50)->nullable();
            $table->string('current_session_id', 255)->nullable();
            $table->unique(['club_id', 'device_id'], 'club_computers_club_device_id_unique');
            $table->unique('device_id', 'club_computers_device_id_unique');
            $table->index(['club_id', 'device_name'], 'club_computers_club_device_name_index');
        });

        DB::table('club_computers')->orderBy('id')->chunkById(500, function ($computers): void {
            foreach ($computers as $computer) {
                DB::table('club_computers')
                    ->where('id', $computer->id)
                    ->update(['device_name' => sprintf('PC-%02d', $computer->number)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->dropUnique('club_computers_club_device_id_unique');
            $table->dropUnique('club_computers_device_id_unique');
            $table->dropIndex('club_computers_club_device_name_index');
            $table->dropColumn([
                'device_name',
                'device_id',
                'agent_state',
                'agent_version',
                'current_session_id',
            ]);
        });
    }
};
