<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Symfony\Component\Mailer\SentMessage;

/*
 * The one deliberately un-faked test: real HTTP request, real sync queue,
 * real event dispatch after commit, real queued listeners, real auto-assign
 * job, real notifications rendered and handed to the (in-memory) mail
 * transport. Every other test fakes at least one of these seams.
 */

/** @return array<string, list<string>> recipient email => subjects */
function deliveredMail(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
        ->flatMap(fn ($message) => collect($message->getTo())->map(fn ($to) => [$to->getAddress(), $message->getSubject()]))
        ->groupBy(0)
        ->map(fn ($rows) => $rows->pluck(1)->sort()->values()->all())
        ->sortKeys()
        ->all();
}

test('a customer ticket is numbered, auto-assigned to the right agent and every recipient gets email', function () {
    config(['queue.default' => 'sync', 'mail.default' => 'array', 'support.assignment.auto_on_create' => true]);

    $billing = Category::factory()->create(['name' => 'Billing']);
    $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
    $specialist = User::factory()->agent()->create(['email' => 'specialist@example.com', 'last_assigned_at' => now()->subDay()]);
    $specialist->categories()->attach($billing);
    $busy = User::factory()->agent()->create(['email' => 'busy@example.com']);
    $busy->categories()->attach($billing);
    Ticket::factory()->inProgress()->assigned($busy)->critical()->create(); // heavier workload
    User::factory()->agent()->unavailable()->create(['email' => 'away@example.com']);
    $customer = User::factory()->customer()->create(['email' => 'cara@example.com']);
    app('mailer')->getSymfonyTransport()->flush();

    $this->actingAs($customer)->post(route('tickets.store'), [
        'subject' => 'Charged twice',
        'description' => 'My card was charged twice for November.',
        'priority' => 'high',
        'category_id' => $billing->id,
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'Charged twice')->sole();

    // Numbered, SLA stamped, assigned by least workload, moved to in progress.
    expect($ticket->ticket_number)->toMatch('/^TKT-2026-\d{6}$/')
        ->and($ticket->due_at)->not->toBeNull()
        ->and($ticket->assigned_agent_id)->toBe($specialist->id)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->activities()->pluck('type')->map->value->all())
        ->toContain(ActivityType::TicketCreated->value, ActivityType::Assigned->value);

    // Mail actually left through the transport, to exactly these people.
    $n = "[{$ticket->ticket_number}]";
    expect(deliveredMail())->toBe([
        'admin@example.com' => ["{$n} New ticket: Charged twice"],
        'busy@example.com' => ["{$n} New ticket: Charged twice"],
        'cara@example.com' => ["{$n} We received your ticket", "{$n} Your ticket has an agent"],
        'specialist@example.com' => ["{$n} New ticket: Charged twice", "{$n} Ticket assigned to you"],
    ]); // nothing for the unavailable agent

    // And the same events landed in the in-app bell.
    expect($specialist->notifications()->count())->toBe(2)
        ->and($customer->notifications()->count())->toBe(2)
        ->and($admin->notifications()->count())->toBe(1);
});
