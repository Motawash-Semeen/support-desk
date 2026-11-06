<?php

namespace App\Models;

use App\Enums\ReplyType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
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

    /** @return HasMany<TicketReply, $this> */
    public function internalNotes(): HasMany
    {
        return $this->hasMany(TicketReply::class)->where('type', ReplyType::InternalNote);
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
     * Row-level visibility: customers see only tickets they created, staff see all.
     * Served by tickets_created_by_created_at_index for customers.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isStaff()) {
            $query->where('created_by', $user->getKey());
        }
    }

    /** Active and past due. Served by tickets_status_due_at_index. */
    public function scopeSlaBreached(Builder $query): void
    {
        $query->active()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    /** Active, past the warning point, not yet due. Served by tickets_status_sla_warning_at_index. */
    public function scopeSlaApproaching(Builder $query): void
    {
        $query->active()
            ->whereNotNull('sla_warning_at')
            ->where('sla_warning_at', '<=', now())
            ->where('due_at', '>=', now());
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
     * Keyword search on subject + description. Uses the FULLTEXT index on
     * MySQL/MariaDB; falls back to LIKE on other drivers (SQLite tests).
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereFullText(['subject', 'description'], $term);

            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $query->where(fn (Builder $q) => $q
            ->where('subject', 'like', $like)
            ->orWhere('description', 'like', $like));
    }

    /* ------------------------------------------------------------------ */
    /* State helpers */
    /* ------------------------------------------------------------------ */

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isSlaBreached(): bool
    {
        return $this->isActive() && $this->due_at !== null && $this->due_at->isPast();
    }

    /**
     * 'breached' | 'at_risk' | 'on_track' for active tickets, null otherwise.
     * Same rules as scopeSlaBreached() / scopeSlaApproaching().
     */
    public function slaState(): ?string
    {
        if (! $this->isActive() || $this->due_at === null) {
            return null;
        }

        if ($this->due_at->isPast()) {
            return 'breached';
        }

        return $this->sla_warning_at !== null && $this->sla_warning_at->lte(now()) ? 'at_risk' : 'on_track';
    }
}
