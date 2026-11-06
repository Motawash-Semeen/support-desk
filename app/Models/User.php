<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * `role`, `is_available` and `last_assigned_at` are deliberately NOT fillable:
     * they change only through dedicated Actions (forceFill), so no form can
     * escalate a user's role by posting an extra field.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Mirrors the column defaults so a freshly created model reports them
     * without a re-query.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'customer',
        'is_available' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_available' => 'boolean',
            'last_assigned_at' => 'datetime',
        ];
    }

    /** @return HasMany<Ticket, $this> */
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }

    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_agent_id');
    }

    /** @return HasMany<TicketReply, $this> */
    public function ticketReplies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /** @return HasMany<TicketImport, $this> */
    public function ticketImports(): HasMany
    {
        return $this->hasMany(TicketImport::class);
    }

    /**
     * Users referenced by tickets, replies or imports cannot be deleted
     * (restrictive foreign keys keep the audit trail intact).
     */
    public function hasTicketHistory(): bool
    {
        return $this->createdTickets()->exists()
            || $this->assignedTickets()->exists()
            || $this->ticketReplies()->exists()
            || $this->ticketImports()->exists()
            || Attachment::query()->where('uploaded_by', $this->getKey())->exists();
    }

    /**
     * Categories an agent specialises in (used by auto-assignment).
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'agent_category');
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isAgent(): bool
    {
        return $this->role === Role::Agent;
    }

    public function isCustomer(): bool
    {
        return $this->role === Role::Customer;
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    /** @param Builder<self> $query */
    public function scopeRole(Builder $query, Role $role): void
    {
        $query->where('role', $role);
    }

    /** @param Builder<self> $query */
    public function scopeAgents(Builder $query): void
    {
        $query->where('role', Role::Agent);
    }

    /** Agents that can receive new tickets. Served by users_role_available_last_assigned_index. */
    public function scopeAvailableAgents(Builder $query): void
    {
        $query->where('role', Role::Agent)->where('is_available', true);
    }
}
