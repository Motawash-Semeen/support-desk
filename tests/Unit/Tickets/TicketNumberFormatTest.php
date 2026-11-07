<?php

use App\Services\TicketNumberGenerator;
use Tests\TestCase;

/*
 * The pure formatting half of the generator (no counter row, no lock).
 * Sequencing, rollback and uniqueness are feature tests in
 * tests/Feature/Tickets/TicketNumberGeneratorTest.php.
 */

uses(TestCase::class);

beforeEach(fn () => config(['support.ticket_prefix' => 'TKT', 'support.ticket_number_padding' => 6]));

test('ticket numbers read PREFIX-YEAR-zero-padded sequence', function (int $year, int $number, string $expected) {
    expect((new TicketNumberGenerator)->format($year, $number))->toBe($expected);
})->with([
    'first of the year' => [2026, 1, 'TKT-2026-000001'],
    'mid range' => [2026, 4521, 'TKT-2026-004521'],
    'last padded value' => [2027, 999_999, 'TKT-2027-999999'],
]);

test('ticket numbers widen past the padding instead of wrapping or truncating', function () {
    expect((new TicketNumberGenerator)->format(2026, 1_000_000))->toBe('TKT-2026-1000000')
        ->and(strlen('TKT-2026-1000000'))->toBeLessThanOrEqual(20); // tickets.ticket_number is VARCHAR(20)
});

test('ticket numbers follow the configured prefix and padding', function () {
    config(['support.ticket_prefix' => 'HELP', 'support.ticket_number_padding' => 4]);

    expect((new TicketNumberGenerator)->format(2026, 7))->toBe('HELP-2026-0007');
});

test('padded ticket numbers sort as text in issue order within a year', function () {
    $generator = new TicketNumberGenerator;
    $numbers = array_map(fn (int $n) => $generator->format(2026, $n), [9, 10, 99, 100, 999]);
    $sorted = $numbers;
    sort($sorted, SORT_STRING);

    expect($sorted)->toBe($numbers)
        ->and($numbers)->each->toMatch('/^TKT-\d{4}-\d{6,}$/');
});
