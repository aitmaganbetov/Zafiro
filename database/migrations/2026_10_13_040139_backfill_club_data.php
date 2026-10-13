<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $clubId = DB::table('clubs')->where('slug', 'legacy')->value('id');

        if ($clubId === null) {
            $clubId = DB::table('clubs')->insertGetId([
                'name' => config('app.name', 'Zafiro'),
                'slug' => 'legacy',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('users')->get() as $user) {
            DB::table('club_memberships')->insertOrIgnore([
                'club_id' => $clubId,
                'user_id' => $user->id,
                'role' => $user->role ?? 'Пользователь',
                'discount' => $user->discount ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('member_balances')->insertOrIgnore([
                'club_id' => $clubId,
                'user_id' => $user->id,
                'balance' => $user->balance ?? 0,
                'bonus_balance' => $user->bonus_balance ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            'products', 'product_categories', 'stock_movements', 'cash_shifts',
            'cash_transactions', 'club_zones', 'club_computers', 'tariffs', 'activity_logs', 'club_zone_tariff',
        ] as $tableName) {
            DB::table($tableName)->whereNull('club_id')->update(['club_id' => $clubId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep ownership data on rollback; merging tenants back is lossy.
    }
};
