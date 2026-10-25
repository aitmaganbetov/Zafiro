<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A message from the club map: the agent shows it in the top right corner of the screen. */
    public function up(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->string('staff_message', 300)->nullable();
            $table->timestamp('staff_message_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->dropColumn(['staff_message', 'staff_message_at']);
        });
    }
};
