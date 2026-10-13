<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $club = Club::query()->where('slug', 'legacy')->firstOrFail();
        app(CurrentClub::class)->set($club);

        $this->call(ProductSeeder::class);
        $this->call(ProductCategorySeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
