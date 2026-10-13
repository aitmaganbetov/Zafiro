<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $planId = DB::table('saas_plans')->where('slug', 'trial')->value('id');

        if ($planId === null) {
            $planId = DB::table('saas_plans')->insertGetId([
                'slug' => 'trial',
                'name' => 'Пробный период',
                'price_monthly' => null,
                'trial_days' => 14,
                'features' => json_encode([]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $legacyClub = DB::table('clubs')->where('slug', 'legacy')->first();

        if ($legacyClub !== null) {
            DB::table('club_subscriptions')->insertOrIgnore([
                'club_id' => $legacyClub->id,
                'saas_plan_id' => $planId,
                'status' => 'active',
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep the plan and subscription state if this data migration is rolled back.
    }
};
