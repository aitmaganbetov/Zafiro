<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every activity log entry must name the acting user and the client IP.
     */
    public function up(): void
    {
        $this->alterWithoutMembershipForeignKey(function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->string('ip_address', 45)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->alterWithoutMembershipForeignKey(function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('ip_address', 45)->nullable()->change();
        });
    }

    /**
     * MySQL does not allow modifying a column that is part of a foreign key, so the
     * (club_id, user_id) -> club_memberships key is dropped around the change.
     */
    private function alterWithoutMembershipForeignKey(Closure $change): void
    {
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        if ($mysql) {
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->dropForeign('activity_logs_club_user_fk');
            });
        }

        Schema::table('activity_logs', $change);

        if ($mysql) {
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->foreign(['club_id', 'user_id'], 'activity_logs_club_user_fk')
                    ->references(['club_id', 'user_id'])->on('club_memberships');
            });
        }
    }
};
