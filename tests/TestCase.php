<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Every framework test starts from the same deterministic world:
 *
 *  - the clock is frozen (Carbon::setTestNow) at FROZEN_NOW, a Monday inside
 *    business hours; tests that need another moment travel explicitly;
 *  - Faker is seeded from the test name, so a failure reproduces exactly;
 *  - the private attachment/import disk is faked (nothing touches storage/);
 *  - any outgoing HTTP call fails the test;
 *  - Blade's @vite never needs a built manifest (CI does not run npm).
 *
 * Mail uses the in-memory `array` transport and the queue is `sync`
 * (phpunit.xml); tests that assert on dispatch fake Queue/Mail/Notification.
 */
abstract class TestCase extends BaseTestCase
{
    /** Monday 2026-11-09 10:00 Asia/Dhaka. */
    public const FROZEN_NOW = '2026-11-09 04:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FROZEN_NOW, 'UTC'));
        fake()->seed(crc32(static::class.'::'.$this->name()));
        Storage::fake('private');
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    /** Set by commitTestTransaction(): tables must be emptied in tearDown. */
    private bool $committedRows = false;

    /**
     * Commit the transaction RefreshDatabase wraps around the test, so the code
     * under test commits for real, as in production: InnoDB FULLTEXT then sees
     * the rows, and a huge import is not undone by a slow rollback.
     *
     * Committed rows outlive the test, so tearDown() empties every table
     * (pass or fail) and reopens a transaction for RefreshDatabase to roll
     * back; otherwise Laravel would re-run every migration for the next test.
     * Migrations seed no rows, so empty tables are the freshly migrated state.
     */
    protected function commitTestTransaction(): void
    {
        $connection = DB::connection();

        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        $this->committedRows = true;
    }

    protected function tearDown(): void
    {
        if ($this->committedRows && $this->app !== null) {
            $this->emptyAllTables();
        }

        parent::tearDown();
    }

    /** Big tables are truncated (O(1)); small ones deleted (no DDL cost). */
    private function emptyAllTables(): void
    {
        $connection = DB::connection();

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        Schema::withoutForeignKeyConstraints(function () use ($connection): void {
            foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), false) as $table) {
                if (in_array($table, ['migrations', 'sqlite_sequence'], true)) {
                    continue;
                }

                $rows = $connection->table($table)->count();

                match (true) {
                    $rows === 0 => null,
                    $rows > 1000 => $connection->table($table)->truncate(),
                    default => $connection->table($table)->delete(),
                };
            }
        });

        $connection->beginTransaction();
    }
}
