<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot of the machine and the queue, once a minute. `minute` is Unix time / 60.
        Schema::create('server_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('minute')->unique();
            $table->decimal('load_1', 6, 2)->nullable();
            $table->decimal('load_5', 6, 2)->nullable();
            $table->decimal('load_15', 6, 2)->nullable();
            $table->unsignedSmallInteger('cpu_count')->nullable();
            $table->unsignedInteger('memory_total_mb')->nullable();
            $table->unsignedInteger('memory_used_mb')->nullable();
            $table->unsignedBigInteger('disk_total_mb')->nullable();
            $table->unsignedBigInteger('disk_used_mb')->nullable();
            $table->unsignedInteger('uptime_seconds')->nullable();
            $table->unsignedBigInteger('database_mb')->nullable();
            $table->unsignedInteger('queue_pending')->default(0);
            $table->unsignedInteger('queue_failed')->default(0);
            $table->unsignedInteger('queue_oldest_seconds')->nullable();
        });

        // Requests per minute and channel (panel, agent, api, guest): count, 5xx, total time.
        Schema::create('request_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('minute');
            $table->string('channel', 16);
            $table->unsignedInteger('requests');
            $table->unsignedInteger('errors');
            $table->unsignedBigInteger('duration_ms');
            $table->unique(['minute', 'channel']);
        });

        // Load a club puts on the server: requests of its PC agents and its API keys per minute.
        Schema::create('club_request_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minute');
            $table->unsignedInteger('agent_requests')->default(0);
            $table->unsignedInteger('api_requests')->default(0);
            $table->unique(['club_id', 'minute']);
            $table->index('minute');
        });

        // Club PCs every 5 minutes; weekday (1 = Monday) and hour are in the club's time zone, for the heat map.
        Schema::create('club_load_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->unsignedTinyInteger('weekday');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('computers');
            $table->unsignedInteger('online');
            $table->unsignedInteger('busy');
            $table->index(['club_id', 'captured_at']);
            $table->index('captured_at');
        });

        // The clubs table of the cabinet: last staff sign-in and sessions per club, failed webhooks per club.
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['club_id', 'event', 'created_at']);
        });
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->index(['club_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['club_id', 'status', 'created_at']);
        });
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['club_id', 'event', 'created_at']);
        });
        Schema::dropIfExists('club_load_snapshots');
        Schema::dropIfExists('club_request_metrics');
        Schema::dropIfExists('request_metrics');
        Schema::dropIfExists('server_metrics');
    }
};
