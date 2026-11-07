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
