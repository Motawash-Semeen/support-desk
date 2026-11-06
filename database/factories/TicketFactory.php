<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Demo/test tickets with internally consistent timestamps.
 *
 * SLA dates use a wall-clock approximation (created_at + sla_minutes). Real
 * tickets get due_at from the business-hours SLA calculator.
 *
 * Closures are evaluated after states are merged, so derived columns
 * (sla_minutes, due_at, assigned_at, ...) follow whatever a state overrides.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /** @var array<int, int> per-year counter so factory numbers stay unique in a process */
    protected static array $sequence = [];

    public function definition(): array
    {
        $createdAt = CarbonImmutable::instance(fake()->dateTimeBetween('-30 days', '-2 hours'));

        return [
            'ticket_number' => fn (array $a) => self::nextNumber(self::at($a['created_at'])->year),
            'subject' => rtrim(fake()->sentence(6), '.'),
            'description' => fake()->paragraphs(3, true),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'status' => TicketStatus::Open,
            'category_id' => Category::factory(),
            'created_by' => User::factory()->customer(),
            'assigned_agent_id' => null,
            'created_at' => $createdAt,
            'assigned_at' => null,
            'sla_policy_id' => null,
            'sla_minutes' => fn (array $a) => self::priorityOf($a)->defaultSlaMinutes(),
            'sla_warning_at' => fn (array $a) => self::at($a['created_at'])
                ->addMinutes((int) floor($a['sla_minutes'] * (float) config('support.sla.warning_threshold'))),
            'due_at' => fn (array $a) => self::at($a['created_at'])->addMinutes($a['sla_minutes']),
            'first_response_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
            'is_escalated' => false,
            'escalation_level' => 0,
            'warning_notified_at' => null,
            'breached_notified_at' => null,
            'external_reference' => null,
            'source' => TicketSource::Web,
            // Last on purpose: closures resolve in key order, so this can read
            // resolved_at / closed_at set by workflow states.
            'updated_at' => fn (array $a) => $a['closed_at'] ?? $a['resolved_at'] ?? $a['first_response_at'] ?? $a['created_at'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Workflow states */
    /* ------------------------------------------------------------------ */

    /** Assign to the given agent, keep an agent set by an earlier state, or create one. */
    public function assigned(?User $agent = null): static
    {
        return $this->state(fn (array $a) => [
            'assigned_agent_id' => $agent?->getKey() ?? $a['assigned_agent_id'] ?? User::factory()->agent(),
            'assigned_at' => fn (array $a) => self::capped(self::at($a['created_at'])->addMinutes(fake()->numberBetween(1, 30))),
        ]);
    }

    public function inProgress(): static
    {
        return $this->assigned()->responded()->state(['status' => TicketStatus::InProgress]);
    }

    public function waitingForCustomer(): static
    {
        return $this->assigned()->responded()->state(['status' => TicketStatus::WaitingForCustomer]);
    }

    public function resolved(): static
    {
        return $this->assigned()->responded()->state([
            'status' => TicketStatus::Resolved,
            'resolved_at' => fn (array $a) => self::between($a['first_response_at'], now()),
        ]);
    }

    public function closed(): static
    {
        return $this->resolved()->state([
            'status' => TicketStatus::Closed,
            'closed_at' => fn (array $a) => self::between($a['resolved_at'], now()),
        ]);
    }

    /** Recent enough that the SLA clock is comfortably within target. */
    public function onTrack(): static
    {
        return $this->state(fn (array $a) => [
            'created_at' => now()->subMinutes(fake()->numberBetween(
                10,
                max(11, (int) (self::priorityOf($a)->defaultSlaMinutes() * 0.6)),
            ))->toImmutable(),
        ]);
    }

    /** Active ticket past its due date. */
    public function breached(): static
    {
        return $this->state(fn () => [
            'created_at' => now()->subDays(fake()->numberBetween(3, 6))->toImmutable(),
            'sla_warning_at' => now()->subHours(fake()->numberBetween(3, 8))->toImmutable(),
            'due_at' => now()->subMinutes(fake()->numberBetween(5, 120))->toImmutable(),
        ]);
    }

    /** Active ticket past the warning point but not yet due. */
    public function approaching(): static
    {
        return $this->state(fn () => [
            'created_at' => now()->subHours(fake()->numberBetween(6, 12))->toImmutable(),
            'sla_warning_at' => now()->subMinutes(fake()->numberBetween(5, 60))->toImmutable(),
            'due_at' => now()->addMinutes(fake()->numberBetween(15, 90))->toImmutable(),
        ]);
    }

    public function escalated(int $level = 1): static
    {
        return $this->state([
            'is_escalated' => true,
            'escalation_level' => $level,
        ]);
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    /** Snapshot an existing policy, as ticket creation will. */
    public function withSlaPolicy(SlaPolicy $policy): static
    {
        return $this->state([
            'priority' => $policy->priority,
            'sla_policy_id' => $policy->getKey(),
            'sla_minutes' => $policy->resolution_minutes,
        ]);
    }

    public function imported(): static
    {
        return $this->state([
            'source' => TicketSource::Import,
            'external_reference' => fn () => 'EXT-'.fake()->unique()->numerify('######'),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /** First agent response shortly after assignment. */
    protected function responded(): static
    {
        return $this->state([
            'first_response_at' => fn (array $a) => self::capped(self::at($a['assigned_at'] ?? $a['created_at'])->addMinutes(fake()->numberBetween(5, 90))),
        ]);
    }

    protected static function nextNumber(int $year): string
    {
        static::$sequence[$year] = (static::$sequence[$year] ?? 0) + 1;

        return sprintf(
            '%s-%d-%s',
            config('support.ticket_prefix'),
            $year,
            str_pad((string) static::$sequence[$year], (int) config('support.ticket_number_padding'), '0', STR_PAD_LEFT),
        );
    }

    protected static function priorityOf(array $attributes): TicketPriority
    {
        $priority = $attributes['priority'];

        return $priority instanceof TicketPriority ? $priority : TicketPriority::from($priority);
    }

    protected static function at(DateTimeInterface|string $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value);
    }

    protected static function capped(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->isFuture() ? CarbonImmutable::now() : $moment;
    }

    protected static function between(DateTimeInterface|string $from, DateTimeInterface|string $to): CarbonImmutable
    {
        $from = self::at($from);
        $to = self::at($to);

        return $from->greaterThanOrEqualTo($to)
            ? $from
            : CarbonImmutable::createFromTimestamp(fake()->numberBetween($from->getTimestamp(), $to->getTimestamp()));
    }
}
