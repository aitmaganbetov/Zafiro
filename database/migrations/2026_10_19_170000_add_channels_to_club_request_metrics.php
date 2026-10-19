<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The whole load of a club, not only its PCs and API keys: the panel of its staff and
        // its guests' app, 5xx answers and the server time its requests took.
        Schema::table('club_request_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('panel_requests')->default(0);
            $table->unsignedInteger('guest_requests')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('club_request_metrics', function (Blueprint $table): void {
            $table->dropColumn(['panel_requests', 'guest_requests', 'errors', 'duration_ms']);
        });
    }
};
