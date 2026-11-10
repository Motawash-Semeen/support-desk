<?php

namespace App\Modules\Tickets;

use App\Models\User;
use App\Modules\Sla\Enums\SlaStatus;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the ticket-list query from the validated filters (TicketIndexRequest).
 * Visibility is applied first, so no filter can reach someone else's tickets.
 * Staff-only filters are ignored for customers, and sorting uses a whitelist.
 */
final class TicketFilter
{
    public const SORTS = ['created_at', 'due_at', 'priority', 'updated_at'];

    public const SLA_STATUSES = ['within_sla', 'approaching', 'breached'];

    /** InnoDB's default innodb_ft_min_token_size: shorter words are not indexed. */
    public const MIN_WORD_LENGTH = 3;

    public const MAX_WORDS = 8;

    private const TICKET_NUMBER = '/^[A-Z]+-\d{4}-\d+$/i';

    public function __construct(
        private readonly User $user,
        private readonly array $filters,
    ) {}

    public function query(): Builder
    {
        $query = Ticket::query()->visibleTo($this->user);

        // Agents list only their own tickets; unassigned ones are in the queue.
        if ($this->user->isAgent()) {
            $query->where('assigned_agent_id', $this->user->getKey());
        }

        // Keyword: a ticket number is an exact lookup, anything else a text search.
        $q = trim((string) ($this->filters['q'] ?? ''));
        if ($q !== '') {
            preg_match(self::TICKET_NUMBER, $q)
                ? $query->where('ticket_number', strtoupper($q))
                : $query->search($q);
        }

        match ($this->scopeValue()) {
            'active' => $query->active(),
            'closed' => $query->closedOrResolved(),
            default => null,
        };

        if ($this->has('status')) {
            $query->whereIn('status', array_map(fn ($s) => TicketStatus::from($s)->value, (array) $this->filters['status']));
        }

        if ($this->has('priority')) {
            $query->whereIn('priority', array_map(fn ($p) => TicketPriority::from($p)->value, (array) $this->filters['priority']));
        }

        if ($this->has('category_id')) {
            $query->where('category_id', (int) $this->filters['category_id']);
        }

        // Dates are days in the business timezone, compared in UTC.
        if ($this->has('created_from')) {
            $query->where('created_at', '>=', $this->businessDay($this->filters['created_from'])->utc());
        }
        if ($this->has('created_to')) {
            $query->where('created_at', '<', $this->businessDay($this->filters['created_to'])->addDay()->utc());
        }

        if ($this->user->isAdmin() && $this->has('assigned_agent_id')) {
            $this->filters['assigned_agent_id'] === 'unassigned'
                ? $query->unassigned()
                : $query->assignedTo((int) $this->filters['assigned_agent_id']);
        }

        if ($this->user->isStaff()) {
            if ($this->has('customer')) {
                $query->where('created_by', (int) $this->filters['customer']);
            }

            if ($this->has('sla_status')) {
                $query->slaStatus(SlaStatus::from($this->filters['sla_status']));
            }

            if ($this->has('escalated') && filter_var($this->filters['escalated'], FILTER_VALIDATE_BOOL)) {
                $query->active()->where('is_escalated', true);
            }
        }

        [$sort, $direction] = $this->sortValue();

        if ($sort === 'priority') {
            // Urgency weight from the enum, as bound values.
            $cases = collect(TicketPriority::cases());
            $query->orderByRaw(
                'CASE priority '.$cases->map(fn () => 'WHEN ? THEN ?')->implode(' ').' ELSE 0 END '.$direction,
                $cases->flatMap(fn (TicketPriority $p) => [$p->value, $p->sortWeight()])->all(),
            );
        } else {
            $query->orderBy('tickets.'.$sort, $direction);
        }

        return $query->orderBy('tickets.id', $direction); // stable pages
    }

    /**
     * 'active', 'closed' or null (all). Without a scope the list shows active
     * tickets, unless statuses were picked.
     */
    public function scopeValue(): ?string
    {
        $scope = $this->filters['scope'] ?? null;

        if ($scope === 'all') {
            return null;
        }

        return $scope ?? ($this->has('status') ? null : 'active');
    }

    /** [sort, direction] actually used. */
    public function sortValue(): array
    {
        $default = $this->user->isStaff() && $this->scopeValue() === 'active' ? ['due_at', 'asc'] : ['created_at', 'desc'];
        $sort = $this->filters['sort'] ?? $default[0];
        $direction = $this->filters['direction'] ?? ($this->has('sort') ? 'desc' : $default[1]);

        return [in_array($sort, self::SORTS, true) ? $sort : $default[0], $direction === 'asc' ? 'asc' : 'desc'];
    }

    /**
     * Free text as a MySQL BOOLEAN MODE expression: every word required and
     * prefix-matched ("+print* +jam*"). Operators typed by the user are
     * stripped. Null when no word is long enough to search.
     */
    public static function booleanQuery(string $input): ?string
    {
        $words = array_values(array_filter(self::words($input), fn (string $word) => mb_strlen($word) >= self::MIN_WORD_LENGTH));

        if ($words === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $word) => '+'.$word.'*', array_slice($words, 0, self::MAX_WORDS)));
    }

    /** Letters, digits and underscores only. */
    public static function words(string $input): array
    {
        $clean = preg_replace('/[^\p{L}\p{M}\p{N}_]+/u', ' ', $input) ?? '';

        return preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Escapes LIKE wildcards so user input matches literally. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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
