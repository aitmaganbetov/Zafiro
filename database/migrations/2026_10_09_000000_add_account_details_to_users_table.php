<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('Пользователь');
            $table->decimal('discount', 5, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('bonus_balance', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'discount', 'balance', 'bonus_balance']);
        });
    }
};