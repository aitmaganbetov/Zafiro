<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chat of a customer (PC agent) with the club staff (panel): one conversation per customer.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_staff');
            $table->text('body');
            $table->foreignId('club_computer_id')->nullable()->constrained()->nullOnDelete();
            // Read by the other side: staff read customer messages, the customer reads staff answers.
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'customer_id', 'id']);
            $table->index(['club_id', 'from_staff', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
