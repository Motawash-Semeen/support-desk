<?php

use App\Modules\Notifications\TicketNotification;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the framework and refresh the database (MySQL or SQLite,
| tests/bootstrap.php). Unit tests stay framework-free unless they opt in.
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
| moduleClasses('Controller') lists every class in a module root whose name
| ends with that word (App\Modules\Tickets\TicketController, ...), and
| moduleNamespaces('Requests') every module's namespace for that sub-folder,
| for architecture tests.
|
*/

function moduleClasses(string $suffix): array
{
    $files = glob(dirname(__DIR__).'/app/Modules/*/*'.$suffix.'.php') ?: [];

    return array_values(array_map(
        fn (string $file): string => 'App\\Modules\\'.basename(dirname($file)).'\\'.basename($file, '.php'),
        $files,
    ));
}

function moduleNamespaces(string $folder): array
{
    $dirs = glob(dirname(__DIR__).'/app/Modules/*/'.$folder, GLOB_ONLYDIR) ?: [];

    return array_values(array_map(
        fn (string $dir): string => 'App\\Modules\\'.basename(dirname($dir)).'\\'.$folder,
        $dirs,
    ));
}

/*
|--------------------------------------------------------------------------
| Notification helpers
|--------------------------------------------------------------------------
|
| Every ticket notification is a TicketNotification; its `kind` says what
| happened (listed in TicketNotifier). ofKind() is the callback for Notification
| assertions:
|
|   Notification::assertSentTo($customer, TicketNotification::class, ofKind('ticket_resolved'));
|
*/

function ofKind(string ...$kinds): Closure
{
    return fn (TicketNotification $notification): bool => in_array($notification->kind, $kinds, true);
}

/** A ready-made notification, for tests about delivery and the in-app list. */
function resolvedNotification(Ticket $ticket): TicketNotification
{
    return new TicketNotification($ticket, 'ticket_resolved', 'Your ticket was resolved',
        "Your ticket {$ticket->ticket_number} was marked resolved. Reply or reopen it if the problem is not fixed.");
}
