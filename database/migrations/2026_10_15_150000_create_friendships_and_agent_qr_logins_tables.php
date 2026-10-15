<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Friends among the customers of one club: a request until the other one accepts it.
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('addressee_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'requester_id', 'addressee_id']);
            $table->index(['club_id', 'addressee_id']);
        });

        // Sign-in on a PC from the phone: the PC shows a QR code, the customer confirms on the site.
        Schema::create('agent_qr_logins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_computer_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('approved_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_qr_logins');
        Schema::dropIfExists('friendships');
    }
};
