<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Club-scoped API keys, request audit, idempotency and webhooks. Additive only.
     */
    public function up(): void
    {
        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->boolean('can_manage_integrations')->default(false);
        });

        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name', 100);
            $table->string('prefix', 16)->unique();
            $table->char('key_hash', 64);
            $table->json('scopes');
            $table->json('allowed_ips')->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->foreignId('rotated_from_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->timestamps();
            $table->index(['club_id', 'revoked_at']);
        });

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('club_id')->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('key_prefix', 16)->nullable();
            $table->string('method', 8);
            $table->string('endpoint', 191);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['club_id', 'created_at']);
            $table->index(['api_key_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['api_key_id', 'idempotency_key']);
            $table->index('created_at');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('url', 500);
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
            $table->index(['club_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('event', 64);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['webhook_endpoint_id', 'created_at']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_keys');
        Schema::table('club_memberships', function (Blueprint $table): void {
            $table->dropColumn('can_manage_integrations');
        });
    }
};
