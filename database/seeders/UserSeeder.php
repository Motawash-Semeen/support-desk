<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo accounts (password: "password"). Idempotent by email.
 *
 *   admin@example.com
 *   agent1..3@example.com    (each specialised in some categories)
 *   customer1..5@example.com
 */
class UserSeeder extends Seeder
{
    /** @var array<string, list<string>> agent email => category slugs */
    private const AGENT_SPECIALITIES = [
        'agent1@example.com' => ['billing', 'account-login', 'general-inquiry'],
        'agent2@example.com' => ['technical-support', 'bug-report'],
        'agent3@example.com' => ['technical-support', 'feature-request', 'general-inquiry'],
    ];

    public function run(): void
    {
        $password = Hash::make('password');

        $this->upsert('admin@example.com', 'Ada Admin', Role::Admin, $password);

        $categories = Category::query()->pluck('id', 'slug');
        $agentNames = ['Sam Agent', 'Riya Agent', 'Tariq Agent'];

        foreach (array_keys(self::AGENT_SPECIALITIES) as $i => $email) {
            $agent = $this->upsert($email, $agentNames[$i], Role::Agent, $password);

            $agent->categories()->sync(
                $categories->only(self::AGENT_SPECIALITIES[$email])->values()->all()
            );
        }

        foreach (range(1, 5) as $i) {
            $this->upsert("customer{$i}@example.com", fake()->name(), Role::Customer, $password);
        }
    }

    private function upsert(string $email, string $name, Role $role, string $hashedPassword): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        // role is not mass assignable by design; forceFill is the explicit path.
        $user->forceFill([
            'name' => $user->exists ? $user->name : $name,
            'password' => $user->exists ? $user->password : $hashedPassword,
            'role' => $role,
            'is_available' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'remember_token' => $user->remember_token ?? Str::random(10),
        ])->save();

        return $user;
    }
}
