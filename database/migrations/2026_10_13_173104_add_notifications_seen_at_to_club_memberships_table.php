<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the member last opened the notifications panel in this club.
     */
    public function up(): void
    {
        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->timestamp('notifications_seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->dropColumn('notifications_seen_at');
        });
    }
};
