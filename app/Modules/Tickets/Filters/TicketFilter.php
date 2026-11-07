<?php

namespace App\Modules\Tickets\Filters;

use App\Models\User;
use App\Modules\Sla\Enums\SlaStatus;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the validated ticket-list parameters (TicketIndexRequest) to a
 * Ticket query. visibleTo() is ALWAYS applied first, so no combination of
 * filters can reach another customer's tickets; staff-only filters are
 * silently ignored for customers. Every value is bound, and sorting comes
 * from a whitelist. This class is also the seam where Laravel Scout could
 * replace the keyword filter later.
 */
final class TicketFilter
{
    /** sort key => column (or 'priority', which sorts by urgency weight) */
    public const SORTS = ['created_at', 'due_at', 'priority', 'updated_at'];

    public const SLA_STATUSES = ['within_sla', 'approaching', 'breached'];

    public const STAFF_ONLY = ['assigned_agent_id', 'customer', 'sla_status', 'escalated'];

    private const TICKET_NUMBER = '/^[A-Z]+-\d{4}-\d+$/i';

    /**
     * @param  array<string, mixed>  $filters  validated input
     */
    public function __construct(
        private readonly User $user,
        private readonly array $filters,
    ) {}

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function apply(Builder $query): Builder
    {
        $query->visibleTo($this->user);

        $this->keyword($query);
        $this->scope($query);
        $this->statuses($query);
        $this->priorities($query);
        $this->category($query);
        $this->createdRange($query);

        if ($this->user->isStaff()) {
            $this->assignee($query);
            $this->customer($query);
            $this->slaStatus($query);
            $this->escalated($query);
        }

        $this->sort($query);

        return $query;
    }

    /**
     * active | closed | null (all). `scope=all` asks for everything; without a
     * scope the list defaults to active unless explicit statuses were chosen.
     */
    public function scopeValue(): ?string
    {
        $scope = $this->filters['scope'] ?? null;

        if ($scope === 'all') {
            return null;
        }

        return $scope ?? ($this->has('status') ? null : 'active');
    }

    /** @return array{0: string, 1: string} [sort, direction] actually applied */
    public function sortValue(): array
    {
        $default = $this->user->isStaff() && $this->scopeValue() === 'active' ? ['due_at', 'asc'] : ['created_at', 'desc'];
        $sort = $this->filters['sort'] ?? $default[0];
        $direction = $this->filters['direction'] ?? ($this->has('sort') ? 'desc' : $default[1]);

        return [in_array($sort, self::SORTS, true) ? $sort : $default[0], $direction === 'asc' ? 'asc' : 'desc'];
    }

    /** @param  Builder<Ticket>  $query */
    private function keyword(Builder $query): void
    {
        $q = trim((string) ($this->filters['q'] ?? ''));

        if ($q === '') {
            return;
        }

        // A ticket number is an exact lookup on the unique index.
        if (preg_match(self::TICKET_NUMBER, $q)) {
            $query->where('ticket_number', strtoupper($q));

            return;
        }

        $query->search($q);
    }

    /** @param  Builder<Ticket>  $query */
    private function scope(Builder $query): void
    {
        match ($this->scopeValue()) {
            'active' => $query->active(),
            'closed' => $query->closedOrResolved(),
            default => null,
        };
    }

    /** @param  Builder<Ticket>  $query */
    private function statuses(Builder $query): void
    {
        if ($this->has('status')) {
            $query->whereIn('status', array_map(fn ($s) => TicketStatus::from($s)->value, (array) $this->filters['status']));
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function priorities(Builder $query): void
    {
        if ($this->has('priority')) {
            $query->whereIn('priority', array_map(fn ($p) => TicketPriority::from($p)->value, (array) $this->filters['priority']));
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function category(Builder $query): void
    {
        if ($this->has('category_id')) {
            $query->where('category_id', (int) $this->filters['category_id']);
        }
    }

    /**
     * Dates are calendar days in the business timezone; the bounds are converted
     * to UTC so they compare correctly with the stored timestamps.
     *
     * @param  Builder<Ticket>  $query
     */
    private function createdRange(Builder $query): void
    {
        if ($this->has('created_from')) {
            $query->where('created_at', '>=', $this->businessDay($this->filters['created_from'])->utc());
        }

        if ($this->has('created_to')) {
            $query->where('created_at', '<', $this->businessDay($this->filters['created_to'])->addDay()->utc());
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function assignee(Builder $query): void
    {
        if (! $this->has('assigned_agent_id')) {
            return;
        }

        $value = $this->filters['assigned_agent_id'];

        $value === 'unassigned'
            ? $query->unassigned()
            : $query->assignedTo((int) $value);
    }

    /** @param  Builder<Ticket>  $query */
    private function customer(Builder $query): void
    {
        if ($this->has('customer')) {
            $query->where('created_by', (int) $this->filters['customer']);
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function slaStatus(Builder $query): void
    {
        if ($this->has('sla_status')) {
            $query->slaStatus(SlaStatus::from($this->filters['sla_status']));
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function escalated(Builder $query): void
    {
        if ($this->has('escalated') && filter_var($this->filters['escalated'], FILTER_VALIDATE_BOOL)) {
            $query->active()->where('is_escalated', true);
        }
    }

    /** @param  Builder<Ticket>  $query */
    private function sort(Builder $query): void
    {
        [$sort, $direction] = $this->sortValue();

        if ($sort === 'priority') {
            // Urgency weight from the enum; the CASE has bound values and the
            // direction is from the whitelist.
            $cases = collect(TicketPriority::cases());
            $query->orderByRaw(
                'CASE priority '.$cases->map(fn () => 'WHEN ? THEN ?')->implode(' ').' ELSE 0 END '.$direction,
                $cases->flatMap(fn (TicketPriority $p) => [$p->value, $p->sortWeight()])->all(),
            );
        } else {
            $query->orderBy((new Ticket)->qualifyColumn($sort), $direction);
        }

        $query->orderBy((new Ticket)->qualifyColumn('id'), $direction); // stable pages
    }

    private function has(string $key): bool
    {
        $value = $this->filters[$key] ?? null;

        return $value !== null && $value !== '' && $value !== [];
    }

    private function businessDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, BusinessTime::zone())->startOfDay();
    }
}
