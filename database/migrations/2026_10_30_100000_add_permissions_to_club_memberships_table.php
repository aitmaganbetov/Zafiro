<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rights of one employee set by the owner (ClubPermissions). The club setting "Allow cashiers
     * to make refunds" becomes the refund right of each cashier of such a club.
     */
    public function up(): void
    {
        Schema::table('club_memberships', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        foreach (DB::table('clubs')->get(['id', 'settings']) as $club) {
            $settings = json_decode((string) $club->settings, true);
            if (! is_array($settings) || ! array_key_exists('allow_cashier_refunds', $settings['club'] ?? [])) {
                continue;
            }
            if ($settings['club']['allow_cashier_refunds'] === true) {
                DB::table('club_memberships')->where('club_id', $club->id)->where('role', 'cashier')
                    ->update(['permissions' => json_encode(['cash_refunds' => true])]);
            }
            unset($settings['club']['allow_cashier_refunds']);
            DB::table('clubs')->where('id', $club->id)->update(['settings' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        Schema::table('club_memberships', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
