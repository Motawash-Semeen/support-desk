<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Customer,
            'is_available' => true,
            'last_assigned_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /** Email not verified yet. */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::Admin]);
    }

    public function agent(): static
    {
        return $this->state(['role' => Role::Agent]);
    }

    public function customer(): static
    {
        return $this->state(['role' => Role::Customer]);
    }

    /** An agent who is not taking new tickets. */
    public function unavailable(): static
    {
        return $this->agent()->state(['is_available' => false]);
    }
}
