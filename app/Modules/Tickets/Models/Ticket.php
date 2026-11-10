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
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketSource;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\TicketFilter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    /** Columns list pages need (never the long description). */
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

    /** Columns the services write; request data is always validated and mapped first. */
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

    /** URLs use the ticket number, e.g. /tickets/TKT-2026-000042. */
    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    // --- Relationships ---

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    /** Every reply including internal notes. Staff only. */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /** Replies customers may see. */
    public function publicReplies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->where('type', ReplyType::Public);
    }

    /** Replies the user may see: all for staff, public ones for customers. */
    public function repliesVisibleTo(User $user): HasMany
    {
        return $user->can('viewInternalNotes', $this) ? $this->replies() : $this->publicReplies();
    }

    /** Timeline entries the user may see; customers get only customer-visible types. */
    public function activitiesVisibleTo(User $user): HasMany
    {
        return $user->can('viewInternalNotes', $this)
            ? $this->activities()
            : $this->activities()->whereIn('type', ActivityType::customerVisibleValues());
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    // --- Scopes ---

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

    public function scopeAssignedTo(Builder $query, User|int $user): void
    {
        $query->where('assigned_agent_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Tickets the user may see (same rule as TicketPolicy::canSee): admins all;
     * agents their own plus active unassigned ones; customers their own.
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

    /** Any SLA status, same rules as slaStatus(). */
    public function scopeSlaStatus(Builder $query, SlaStatus $status): void
    {
        $status->applyTo($query, now());
    }

    /** Active and past due. */
    public function scopeSlaBreached(Builder $query): void
    {
        SlaStatus::Breached->applyTo($query, now());
    }

    /** Active, past the warning time, not yet due. */
    public function scopeSlaApproaching(Builder $query): void
    {
        SlaStatus::Approaching->applyTo($query, now());
    }

    /** Active and before the warning point. */
    public function scopeWithinSla(Builder $query): void
    {
        SlaStatus::WithinSla->applyTo($query, now());
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
     * Keyword search: MySQL full-text search on subject and description; LIKE on
     * other databases or for very short words.
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $boolean = TicketFilter::booleanQuery($term);

        if ($boolean !== null && in_array($query->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereFullText(['subject', 'description'], $boolean, ['mode' => 'boolean']);

            return;
        }

        $words = TicketFilter::words($term);
        $literal = TicketFilter::escapeLike($words === [] ? $term : implode(' ', $words));

        $query->where(fn (Builder $q) => $q
            ->where('subject', 'like', $literal.'%')
            ->orWhere('description', 'like', '%'.$literal.'%'));
    }

    // --- State helpers ---

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** SLA status from the stored columns. */
    public function slaStatus(?CarbonInterface $now = null): ?SlaStatus
    {
        return SlaStatus::resolve(
            $this->due_at,
            $this->sla_warning_at,
            $this->resolved_at ?? $this->closed_at,
            $this->isActive(),
            $now ?? now(),
        );
    }
}
