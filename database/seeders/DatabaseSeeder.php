<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Reference data is seeded everywhere; demo users and tickets never in production.
     */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            SlaPolicySeeder::class,
            BusinessHourSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call([
                UserSeeder::class,
                DemoTicketSeeder::class,
            ]);
        }
    }
}
