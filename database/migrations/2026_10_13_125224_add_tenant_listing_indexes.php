<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes for per-club listings ordered or filtered by these columns.
     *
     * @var array<string, array<int, string>>
     */
    private array $indexes = [
        'cash_transactions' => ['club_id', 'created_at'],
        'activity_logs' => ['club_id', 'created_at'],
        'stock_movements' => ['club_id', 'created_at'],
        'products' => ['club_id', 'name'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->indexes as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                $table->index($columns);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->indexes as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                $table->dropIndex($columns);
            });
        }
    }
};
