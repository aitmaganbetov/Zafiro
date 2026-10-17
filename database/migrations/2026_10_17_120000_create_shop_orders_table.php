<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Goods a customer ordered and paid from the balance in the PC agent; the staff bring them to the PC.
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_computer_id')->nullable()->constrained()->nullOnDelete();
            // The PC name at the time of the order: the PC may be renamed or deleted later.
            $table->string('computer_name')->nullable();
            $table->json('items');
            $table->decimal('amount', 12, 2);
            // new → done (handed over) or cancelled (money and goods returned).
            $table->string('status', 20)->default('new');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('handled_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status', 'id']);
            $table->index(['club_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_orders');
    }
};
