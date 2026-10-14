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
            $table->foreignId('current_session_user_id')
                ->nullable()
                ->after('current_session_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_session_user_id');
        });
    }
};
