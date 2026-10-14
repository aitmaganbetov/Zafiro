<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff PIN that closes the PC agent (Ctrl+Alt+P on the PC). Only a hash is stored.
     */
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->string('agent_exit_pin_hash')->nullable();
            $table->timestamp('agent_exit_pin_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->dropColumn(['agent_exit_pin_hash', 'agent_exit_pin_changed_at']);
        });
    }
};
