<?php

namespace Database\Seeders;

use App\Modules\Sla\SlaService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Reference data everywhere; sample users and tickets never in production. */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            SlaPolicySeeder::class,
            BusinessHourSeeder::class,
        ]);

        // Model events are off while seeding, so clear the SLA rule cache here.
        app(SlaService::class)->flushRules();

        if (! app()->isProduction()) {
            $this->call([
                UserSeeder::class,
                TicketSeeder::class,
            ]);
        }
    }
}
