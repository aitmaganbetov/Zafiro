<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agents used to get a new 30-day token on every sign-in and never revoked the old
     * ones. Keep only the newest token per user and device; older copies are revoked.
     * Revoked tokens cannot be restored: the device signs in again if it still used one.
     */
    public function up(): void
    {
        $keep = DB::table('personal_access_tokens')
            ->selectRaw('MAX(id) as id')
            ->groupBy('tokenable_type', 'tokenable_id', 'name')
            ->pluck('id');

        DB::table('personal_access_tokens')->whereNotIn('id', $keep)->delete();
    }

    public function down(): void
    {
        // Revoked tokens are gone on purpose.
    }
};
