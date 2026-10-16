<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A session sold at the cash register to a guest without an account (no customer on it).
        Schema::table('club_computers', function (Blueprint $table) {
            $table->boolean('session_guest')->default(false)->after('current_session_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->dropColumn('session_guest');
        });
    }
};
