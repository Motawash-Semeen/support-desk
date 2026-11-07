<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the framework and refresh the database (MySQL or SQLite,
| see tests/bootstrap.php). Unit tests stay framework-free unless they opt in.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Multi-process races need committed rows: no RefreshDatabase; tests clean up.
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| toBeJsonObject: same keys and strictly identical values, in any key order.
| A JSON object has no key order, and MySQL 8 returns JSON columns with keys
| reordered (shorter keys first) while MariaDB and SQLite keep insertion
| order, so toBe() on a decoded JSON column would fail on MySQL 8 only.
|
*/

expect()->extend('toBeJsonObject', function (array $expected) {
    $actual = $this->value;

    expect($actual)->toBeArray();

    // Nested objects are reordered too, so sort keys at every level.
    $sort = function (array $value) use (&$sort): array {
        ksort($value);

        return array_map(fn ($item) => is_array($item) ? $sort($item) : $item, $value);
    };

    expect($sort($actual))->toBe($sort($expected));

    return $this;
});

/*
|--------------------------------------------------------------------------
| Module helpers
|--------------------------------------------------------------------------
|
| moduleNamespaces('Http\Controllers') lists that layer's namespace in every
| L5Modular module that has it, for architecture tests.
|
*/

/** @return list<string> */
function moduleNamespaces(string $layer): array
{
    $dirs = glob(dirname(__DIR__).'/app/Modules/*/'.str_replace('\\', '/', $layer), GLOB_ONLYDIR) ?: [];

    return array_values(array_map(
        fn (string $dir): string => 'App\\Modules\\'.basename(dirname($dir, substr_count($layer, '\\') + 1)).'\\'.$layer,
        $dirs,
    ));
}
