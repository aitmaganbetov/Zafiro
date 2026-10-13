<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interface languages and their translations, shared by all clubs.
     */
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 64);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('group', 64)->default('*');
            $table->text('key');
            $table->char('key_hash', 40);
            $table->text('value');
            $table->timestamps();
            $table->unique(['language_id', 'group', 'key_hash']);
        });

        Schema::table('clubs', function (Blueprint $table): void {
            $table->string('locale', 16)->nullable();
        });

        $now = now();
        DB::table('languages')->insert([
            ['code' => 'ru', 'name' => 'Русский', 'is_enabled' => true, 'is_system' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'kk', 'name' => 'Қазақша', 'is_enabled' => true, 'is_system' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'en', 'name' => 'English', 'is_enabled' => true, 'is_system' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Artisan::call('translations:sync');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
        Schema::dropIfExists('translations');
        Schema::dropIfExists('languages');
    }
};
