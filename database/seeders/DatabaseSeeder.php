<?php

namespace Database\Seeders;

use App\Services\Sla\SlaPolicyRepository;
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

        // Model events are muted while seeding, so the rule cache is flushed explicitly.
        app(SlaPolicyRepository::class)->flush();

        if (! app()->isProduction()) {
            $this->call([
                UserSeeder::class,
                DemoTicketSeeder::class,
            ]);
        }
    }
}
