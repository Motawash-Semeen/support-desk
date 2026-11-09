<?php

namespace App\Modules\Tickets\Models;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Sla\Enums\SlaStatus;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Services\SlaStatusResolver;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketSource;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Support\FullTextQuery;
use Carbon\CarbonInterface;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * Columns list pages need. Selecting only these keeps list queries narrow
     * (description is TEXT and never shown in lists).
     *
     * @var list<string>
     */
    public const LIST_COLUMNS = [
        'id',
        'ticket_number',
        'subject',
        'priority',
        'status',
        'category_id',
        'created_by',
        'assigned_agent_id',
        'due_at',
        'sla_warning_at',
        'resolved_at',
        'closed_at',
        'is_escalated',
        'created_at',
        'updated_at',
    ];

    /**
     * Fillable is the set of columns Actions write. FormRequests whitelist what a
     * user may submit and Actions map validated input explicitly, so request
     * data never reaches create()/update() unfiltered.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'subject',
        'description',
        'priority',
        'status',
        'category_id',
        'created_by',
        'assigned_agent_id',
        'assigned_at',
        'sla_policy_id',
        'sla_minutes',
        'sla_warning_at',
        'due_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'is_escalated',
        'escalation_level',
        'warning_notified_at',
        'breached_notified_at',
        'external_reference',
        'source',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'source' => 'web',
        'is_escalated' => false,
        'escalation_level' => 0,
    ];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'source' => TicketSource::class,
            'sla_minutes' => 'integer',
            'is_escalated' => 'boolean',
            'escalation_level' => 'integer',
            'assigned_at' => 'datetime',
            'sla_warning_at' => 'datetime',
            'due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'warning_notified_at' => 'datetime',
            'breached_notified_at' => 'datetime',
        ];
    }

    /**
     * URLs use the human ticket number (served by its unique index), e.g.
     * /tickets/TKT-2026-000042.
     */
    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    /* ------------------------------------------------------------------ */
    /* Relationships */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    /**
     * Every reply including internal notes. Staff only.
     *
     * @return HasMany<TicketReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /**
     * Customer-visible replies. The single place the public/internal filter
     * lives; served by ticket_replies_ticket_id_type_index.
     *
     * @return HasMany<TicketReply, $this>
     */
    public function publicReplies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->where('type', ReplyType::Public);
    }

    /**
     * The conversation a given user may see: everything for staff, public
     * replies only for customers. Decided by TicketPolicy::viewInternalNotes,
     * the single gate for internal-note visibility.
     *
     * @return HasMany<TicketReply, $this>
     */
    public function repliesVisibleTo(User $user): HasMany
    {
        return $user->can('viewInternalNotes', $this) ? $this->replies() : $this->publicReplies();
    }

    /**
     * Timeline entries a given user may see. Customers get only types that
     * ActivityType::isCustomerVisible() allows (no notes, assignment, SLA
     * internals or escalation). Served by ticket_activities_ticket_id_created_at_index.
     *
     * @return HasMany<TicketActivity, $this>
     */
    public function activitiesVisibleTo(User $user): HasMany
    {
        return $user->can('viewInternalNotes', $this)
            ? $this->activities()
            : $this->activities()->whereIn('type', ActivityType::customerVisibleValues());
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<TicketActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    /* ------------------------------------------------------------------ */
    /* Scopes */
    /* ------------------------------------------------------------------ */

    /** Open, in progress or waiting for customer. */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', TicketStatus::activeValues());
    }

    public function scopeClosedOrResolved(Builder $query): void
    {
        $query->whereIn('status', TicketStatus::finishedValues());
    }

    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('assigned_agent_id');
    }

    /** Served by tickets_assigned_agent_id_status_index. */
    public function scopeAssignedTo(Builder $query, User|int $user): void
    {
        $query->where('assigned_agent_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Row-level visibility (the same rule as TicketPolicy::view):
     *  - admins: every ticket;
     *  - agents: tickets assigned to them (any status), plus active unassigned
     *    tickets, which they may open from the queue before accepting;
     *  - customers: tickets they created.
     * Served by tickets_agent_cover_index / tickets_created_by_created_at_status_index.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where(fn (Builder $visible) => $visible
                ->where('assigned_agent_id', $user->getKey())
                ->orWhere(fn (Builder $queue) => $queue
                    ->whereNull('assigned_agent_id')
                    ->whereIn('status', TicketStatus::activeValues())));

            return;
        }

        $query->where('created_by', $user->getKey());
    }

    /** Any SLA status, with the same rules as slaStatus() (SlaStatusResolver). */
    public function scopeSlaStatus(Builder $query, SlaStatus $status): void
    {
        SlaStatusResolver::applyScope($query, $status, now());
    }

    /** Active and past due. Served by tickets_status_due_at_index. */
    public function scopeSlaBreached(Builder $query): void
    {
        SlaStatusResolver::applyScope($query, SlaStatus::Breached, now());
    }

    /** Active, past the warning point, not yet due. Served by tickets_status_sla_warning_at_index. */
    public function scopeSlaApproaching(Builder $query): void
    {
        SlaStatusResolver::applyScope($query, SlaStatus::Approaching, now());
    }

    /** Active and before the warning point. */
    public function scopeWithinSla(Builder $query): void
    {
        SlaStatusResolver::applyScope($query, SlaStatus::WithinSla, now());
    }

    /** Relations every list row renders, with only the columns it shows. */
    public function scopeWithListRelations(Builder $query): void
    {
        $query->with([
            'creator:id,name',
            'assignee:id,name',
            'category:id,name',
        ]);
    }

    /** Narrow select for list pages; pair with withListRelations(). */
    public function scopeSelectListColumns(Builder $query): void
    {
        $query->select(array_map(fn (string $column) => $this->qualifyColumn($column), self::LIST_COLUMNS));
    }

    /**
     * Keyword search on subject + description.
     *  - MySQL/MariaDB: MATCH(subject, description) AGAINST(? IN BOOLEAN MODE) on
     *    the FULLTEXT index, with every word required and prefix-matched; input is
     *    sanitised (FullTextQuery) and bound, never interpolated.
     *  - Only very short words (below the FULLTEXT minimum) or another driver
     *    (SQLite tests): subject LIKE 'term%' OR description LIKE '%term%', escaped.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $boolean = FullTextQuery::boolean($term);

        if ($boolean !== null && in_array($query->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereFullText(['subject', 'description'], $boolean, ['mode' => 'boolean']);

            return;
        }

        $words = FullTextQuery::words($term);
        $literal = FullTextQuery::escapeLike($words === [] ? $term : implode(' ', $words));

        $query->where(fn (Builder $q) => $q
            ->where('subject', 'like', $literal.'%')
            ->orWhere('description', 'like', '%'.$literal.'%'));
    }

    /* ------------------------------------------------------------------ */
    /* State helpers */
    /* ------------------------------------------------------------------ */

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * SLA standing from stored columns only (comparisons, no maths). Same rules
     * as the SQL scopes; see SlaStatusResolver.
     */
    public function slaStatus(?CarbonInterface $now = null): ?SlaStatus
    {
        return SlaStatusResolver::resolve(
            $this->due_at,
            $this->sla_warning_at,
            $this->resolved_at ?? $this->closed_at,
            $this->isActive(),
            $now ?? now(),
        );
    }
}
