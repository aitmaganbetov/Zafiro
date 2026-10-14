<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->timestamp('session_ends_at')->nullable()->after('current_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->dropColumn('session_ends_at');
        });
    }
};
