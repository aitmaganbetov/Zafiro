<?php

namespace Database\Factories;

use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $clubId = app(CurrentClub::class)->id();

            if ($clubId === null) {
                return;
            }

            ClubMembership::query()->withoutGlobalScope('club')->firstOrCreate(
                ['club_id' => $clubId, 'user_id' => $user->getKey()],
                ['role' => 'owner', 'discount' => 0],
            );
            MemberBalance::query()->withoutGlobalScope('club')->firstOrCreate(
                ['club_id' => $clubId, 'user_id' => $user->getKey()],
                ['balance' => 0, 'bonus_balance' => 0],
            );
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('+7##########'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** Platform superadmin: manages languages and translations of all clubs. */
    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => ['is_superadmin' => true]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
