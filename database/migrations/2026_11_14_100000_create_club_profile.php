<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the guest app shows about a club: description and contacts, photos, zone equipment.
        Schema::table('clubs', function (Blueprint $table) {
            $table->json('profile')->nullable();
        });

        Schema::table('club_zones', function (Blueprint $table) {
            $table->json('devices')->nullable();
        });

        Schema::create('club_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['club_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_photos');
        Schema::table('club_zones', fn (Blueprint $table) => $table->dropColumn('devices'));
        Schema::table('clubs', fn (Blueprint $table) => $table->dropColumn('profile'));
    }
};
