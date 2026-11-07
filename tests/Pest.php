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
    ksort($actual);
    ksort($expected);

    expect($actual)->toBe($expected);

    return $this;
});
