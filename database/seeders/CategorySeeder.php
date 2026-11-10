<?php

namespace Database\Seeders;

use App\Modules\Tickets\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public const CATEGORIES = [
        'Account & Login',
        'Billing',
        'Bug Report',
        'Feature Request',
        'General Inquiry',
        'Technical Support',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $name) {
            Category::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
