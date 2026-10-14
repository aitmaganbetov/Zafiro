<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff work on a club PC (agent closed with the PIN, or a staff member signed in on it):
 * how long it may last is set per club, and the PC shows it in the panel until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('agent_staff_minutes')->default(15)->after('agent_exit_pin_changed_at');
        });

        Schema::table('club_computers', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->nullable()->after('session_ends_at')->constrained('users')->nullOnDelete();
            $table->string('staff_kind', 16)->nullable()->after('staff_user_id');
            $table->timestamp('staff_until')->nullable()->after('staff_kind');
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_user_id');
            $table->dropColumn(['staff_kind', 'staff_until']);
        });

        Schema::table('clubs', function (Blueprint $table): void {
            $table->dropColumn('agent_staff_minutes');
        });
    }
};
