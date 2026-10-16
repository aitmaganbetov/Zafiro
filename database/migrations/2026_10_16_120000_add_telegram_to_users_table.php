<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telegram of the customer (Zafiro bot): a new password after a reset is sent there.
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->unique();
            $table->dateTime('telegram_linked_at')->nullable();
            // One-time code of the t.me/<bot>?start=<code> link (hash only), until it expires.
            $table->string('telegram_link_hash', 64)->nullable()->unique();
            $table->dateTime('telegram_link_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropUnique(['telegram_link_hash']);
            $table->dropColumn(['telegram_chat_id', 'telegram_linked_at', 'telegram_link_hash', 'telegram_link_expires_at']);
        });
    }
};
