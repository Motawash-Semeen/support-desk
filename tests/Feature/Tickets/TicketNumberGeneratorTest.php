<?php

use App\Actions\Tickets\CreateTicket;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketSequence;
use App\Models\User;
use App\Services\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->numbers = app(TicketNumberGenerator::class);
});

it('issues sequential numbers in the configured format', function () {
    $at = CarbonImmutable::create(2026, 3, 10, 12);

    expect($this->numbers->generate($at))->toBe('TKT-2026-000001')
        ->and($this->numbers->generate($at))->toBe('TKT-2026-000002')
        ->and($this->numbers->generate($at))->toBe('TKT-2026-000003')
        ->and(TicketSequence::query()->find(2026)->last_number)->toBe(3);
});

it('defaults to the current year', function () {
    $this->travelTo(CarbonImmutable::create(2031, 6, 1));

    expect($this->numbers->generate())->toBe('TKT-2031-000001');
});

it('starts again at 000001 when the year rolls over', function () {
    expect($this->numbers->generate(CarbonImmutable::create(2026, 12, 31, 23, 59, 59)))->toBe('TKT-2026-000001')
        ->and($this->numbers->generate(CarbonImmutable::create(2026, 12, 31, 23, 59, 59)))->toBe('TKT-2026-000002')
        ->and($this->numbers->generate(CarbonImmutable::create(2027, 1, 1, 0, 0, 0)))->toBe('TKT-2027-000001')
        ->and($this->numbers->generate(CarbonImmutable::create(2026, 12, 31)))->toBe('TKT-2026-000003');
});

it('does not consume a number when the surrounding transaction rolls back', function () {
    $at = CarbonImmutable::create(2026, 5, 1);

    try {
        DB::transaction(function () use ($at) {
            expect($this->numbers->generate($at))->toBe('TKT-2026-000001');

            throw new RuntimeException('ticket insert failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($this->numbers->generate($at))->toBe('TKT-2026-000001');
});

it('gives the number back when CreateTicket fails to insert the ticket', function () {
    $customer = User::factory()->create();
    $valid = ['subject' => 'Printer', 'description' => 'Jammed', 'priority' => 'low', 'category_id' => Category::factory()->create()->id];

    expect(fn () => app(CreateTicket::class)($customer, [...$valid, 'category_id' => 999_999]))
        ->toThrow(QueryException::class);

    $ticket = app(CreateTicket::class)($customer, $valid);

    expect($ticket->ticket_number)->toBe(sprintf('TKT-%d-000001', now()->year));
});

it('widens past the padding instead of overflowing', function () {
    TicketSequence::query()->create(['year' => 2026, 'last_number' => 999_999]);

    $number = $this->numbers->generate(CarbonImmutable::create(2026, 1, 1));

    expect($number)->toBe('TKT-2026-1000000')
        ->and(strlen($number))->toBeLessThanOrEqual(20);
});

it('reserves a contiguous block with one allocation', function () {
    $at = CarbonImmutable::create(2026, 2, 2);
    $this->numbers->generate($at);

    $block = $this->numbers->reserve(3, $at);

    expect($block)->toBe(['TKT-2026-000002', 'TKT-2026-000003', 'TKT-2026-000004'])
        ->and($this->numbers->generate($at))->toBe('TKT-2026-000005');
});

it('rejects an empty reservation', function () {
    expect(fn () => $this->numbers->reserve(0))->toThrow(InvalidArgumentException::class);
});

it('keeps the unique index as the final safety net', function () {
    $ticket = Ticket::factory()->create();

    expect(fn () => Ticket::factory()->create(['ticket_number' => $ticket->ticket_number]))
        ->toThrow(QueryException::class);
});

it('uses the configured prefix and padding', function () {
    config(['support.ticket_prefix' => 'SUP', 'support.ticket_number_padding' => 4]);

    expect($this->numbers->generate(CarbonImmutable::create(2026, 1, 1)))->toBe('SUP-2026-0001');
});
