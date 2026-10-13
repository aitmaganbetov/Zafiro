<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('clubs')->whereNotNull('installation_key_hash')->exists()) {
            throw new RuntimeException('Existing hash-only installation keys cannot be recovered without rotation.');
        }

        Schema::table('clubs', function (Blueprint $table) {
            $table->text('installation_key')->nullable();
        });

        foreach (DB::table('clubs')->get(['id']) as $club) {
            $key = Str::random(64);
            DB::table('clubs')->where('id', $club->id)->update([
                'installation_key' => Crypt::encryptString($key),
                'installation_key_hash' => hash('sha256', $key),
                'installation_key_prefix' => substr($key, 0, 12),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('installation_key');
        });
    }
};
