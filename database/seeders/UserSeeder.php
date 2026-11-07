<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Tickets\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The support team and its customers. Every account's password is "password".
 * Idempotent by email. Emails use the reserved .test domain, so nothing can
 * ever be delivered to a real mailbox.
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const ADMIN = ['Farhana Haque', 'farhana.haque@supportdesk.test'];

    /** name, email, category slugs (none = covers every category), available */
    public const AGENTS = [
        ['Tanvir Ahmed', 'tanvir.ahmed@supportdesk.test', ['billing', 'account-login'], true],
        ['Nusrat Jahan', 'nusrat.jahan@supportdesk.test', ['technical-support', 'bug-report'], true],
        ['Arif Hossain', 'arif.hossain@supportdesk.test', ['technical-support', 'feature-request', 'general-inquiry'], true],
        ['Sadia Islam', 'sadia.islam@supportdesk.test', ['billing', 'general-inquiry'], true],
        // On leave: keeps old tickets in the history but gets no new work.
        ['Rakib Hasan', 'rakib.hasan@supportdesk.test', ['bug-report', 'technical-support'], false],
    ];

    /** name, email, months since sign-up */
    public const CUSTOMERS = [
        ['Mahmudul Karim', 'mahmudul.karim@greenleaf-traders.test', 9],
        ['Emily Carter', 'emily.carter@northwind-studio.test', 8],
        ['Rashed Chowdhury', 'rashed@chowdhury-textiles.test', 11],
        ['Priya Sharma', 'priya.sharma@bluepeak-consulting.test', 6],
        ['Jonathan Lee', 'jon.lee@harbourlight.test', 7],
        ['Tasnim Akter', 'tasnim.akter@dhakafreshmart.test', 5],
        ['Omar Faruk', 'omar.faruk@faruk-logistics.test', 10],
        ['Laura Bennett', 'laura.bennett@oakridge-dental.test', 4],
        ['Imran Siddiqui', 'imran@siddiqui-electronics.test', 12],
        ['Mehnaz Rahman', 'mehnaz.rahman@craftnest.test', 3],
        ['David Kim', 'david.kim@kimarchitects.test', 6],
        ['Sumaiya Hossain', 'sumaiya@petalandpot.test', 2],
        ['Kamal Uddin', 'kamal.uddin@uddin-pharma.test', 8],
        ['Ayesha Siddika', 'ayesha.siddika@brightminds-school.test', 5],
    ];

    public function run(): void
    {
        $password = Hash::make(self::PASSWORD);
        $categories = Category::query()->pluck('id', 'slug');

        $this->upsert(self::ADMIN[1], self::ADMIN[0], Role::Admin, $password, now()->subYear());

        foreach (self::AGENTS as [$name, $email, $slugs, $available]) {
            $agent = $this->upsert($email, $name, Role::Agent, $password, now()->subMonths(10), $available);
            $agent->categories()->sync($categories->only($slugs)->values()->all());
        }

        foreach (self::CUSTOMERS as [$name, $email, $months]) {
            $this->upsert($email, $name, Role::Customer, $password, now()->subMonths($months)->subDays(crc32($email) % 25));
        }
    }

    private function upsert(string $email, string $name, Role $role, string $hashedPassword, $joinedAt, bool $available = true): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        // role and is_available are not mass assignable by design; forceFill is the explicit path.
        $user->forceFill([
            'name' => $name,
            'password' => $user->exists ? $user->password : $hashedPassword,
            'role' => $role,
            'is_available' => $available,
            'email_verified_at' => $user->email_verified_at ?? $joinedAt,
            'remember_token' => $user->remember_token ?? Str::random(10),
            'created_at' => $user->created_at ?? $joinedAt,
            'updated_at' => $user->updated_at ?? $joinedAt,
        ])->save();

        return $user;
    }
}
