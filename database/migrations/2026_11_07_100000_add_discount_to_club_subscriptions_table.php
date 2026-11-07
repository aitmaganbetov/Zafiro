<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->default(0)->after('canceled_at');
            $table->date('discount_until')->nullable()->after('discount_percent');
            $table->string('discount_note', 200)->nullable()->after('discount_until');
        });
    }

    public function down(): void
    {
        Schema::table('club_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_until', 'discount_note']);
        });
    }
};
