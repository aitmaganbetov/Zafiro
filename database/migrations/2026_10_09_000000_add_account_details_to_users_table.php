<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('Пользователь');
            }
            if (! Schema::hasColumn('users', 'discount')) {
                $table->decimal('discount', 5, 2)->default(0);
            }
            if (! Schema::hasColumn('users', 'balance')) {
                $table->decimal('balance', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('users', 'bonus_balance')) {
                $table->decimal('bonus_balance', 12, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['discount', 'balance', 'bonus_balance'],
                fn (string $column): bool => Schema::hasColumn('users', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};