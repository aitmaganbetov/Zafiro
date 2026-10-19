<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a paid session of a customer is made of (tariffs, minutes, a started package), in
        // order: unused time of a session ended early is refunded or kept from the last part back.
        Schema::create('session_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 36);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // tariff | minutes
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('minutes');
            $table->unsignedBigInteger('main_cents')->default(0);
            $table->unsignedBigInteger('bonus_cents')->default(0);
            // A tariff with a usage limit: its unused time is kept until then.
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['club_id', 'session_id']);
            $table->index('created_at');
        });

        // Unused time of a tariff with a usage limit, kept for the customer until it expires.
        Schema::create('time_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('minutes_left');
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['club_id', 'user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_packages');
        Schema::dropIfExists('session_purchases');
    }
};
