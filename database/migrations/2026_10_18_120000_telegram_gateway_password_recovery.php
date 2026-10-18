<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Password recovery codes sent through Telegram Gateway (hash only), until they expire.
        Schema::create('password_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // The Telegram bot is gone: its chats and link codes, and its token.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropUnique(['telegram_link_hash']);
            $table->dropColumn(['telegram_chat_id', 'telegram_linked_at', 'telegram_link_hash', 'telegram_link_expires_at']);
        });
        DB::table('platform_settings')->where('key', 'telegram_bot')->delete();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->unique();
            $table->dateTime('telegram_linked_at')->nullable();
            $table->string('telegram_link_hash', 64)->nullable()->unique();
            $table->dateTime('telegram_link_expires_at')->nullable();
        });
        Schema::dropIfExists('password_recovery_codes');
    }
};
