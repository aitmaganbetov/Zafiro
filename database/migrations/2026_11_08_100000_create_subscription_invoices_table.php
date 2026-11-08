<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saas_plan_id')->constrained();
            $table->unsignedTinyInteger('months');
            $table->unsignedSmallInteger('computers');
            $table->decimal('price_per_computer', 12, 2);
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->decimal('amount', 12, 2);
            // pending → paid (the superadmin saw the money arrive) or canceled.
            $table->string('status', 16)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
