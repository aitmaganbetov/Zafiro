<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device token of the PC agent: issued to staff when a PC is registered or
     * resolved, stored only as a SHA-256 hash, valid for this one computer.
     */
    public function up(): void
    {
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->char('agent_token_hash', 64)->nullable()->unique();
            $table->timestamp('agent_token_issued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->dropUnique(['agent_token_hash']);
            $table->dropColumn(['agent_token_hash', 'agent_token_issued_at']);
        });
    }
};
