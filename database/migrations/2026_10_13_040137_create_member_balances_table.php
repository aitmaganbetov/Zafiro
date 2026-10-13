<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('member_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('club_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('bonus_balance', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['club_id', 'user_id']);
            $table->foreign(['club_id', 'user_id'], 'member_balances_membership_fk')
                ->references(['club_id', 'user_id'])
                ->on('club_memberships')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_balances');
    }
};
