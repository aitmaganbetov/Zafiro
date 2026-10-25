<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Services a club sells at the cash register without stock: PlayStation by the hour, printing… */
    public function up(): void
    {
        Schema::create('club_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->decimal('price', 12, 2);
            $table->string('unit', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['club_id', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_services');
    }
};
