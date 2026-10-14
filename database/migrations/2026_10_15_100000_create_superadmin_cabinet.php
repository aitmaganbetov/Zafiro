<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform superadmin cabinet: blocked accounts and a platform-wide log of superadmin
 * actions (plans, users, subscriptions, languages), which belong to no single club.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('blocked_at')->nullable()->after('is_superadmin');
        });

        Schema::create('platform_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->string('description', 500);
            $table->nullableMorphs('subject');
            $table->json('details')->nullable();
            $table->string('ip_address', 45);
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->index('created_at');
            $table->index(['club_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_logs');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('blocked_at');
        });
    }
};
