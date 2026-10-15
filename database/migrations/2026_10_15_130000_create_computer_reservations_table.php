<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('club_computer_id');
            // A club customer (may buy time on the PC during the booking) or just a name and phone.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['club_id', 'starts_at']);
            $table->index(['club_computer_id', 'starts_at']);
            // The PC of the same club (SQLite in tests has no composite club keys).
            if (DB::getDriverName() === 'sqlite') {
                $table->foreign('club_computer_id')->references('id')->on('club_computers')->cascadeOnDelete();
            } else {
                $table->foreign(['club_id', 'club_computer_id'], 'computer_reservations_club_computer_fk')
                    ->references(['club_id', 'id'])->on('club_computers')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_reservations');
    }
};
