<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // News on the PC agent's home screen: platform news (no club, by the superadmin) and club news.
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('body');
            $table->string('image_path')->nullable();
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['club_id', 'is_published', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
