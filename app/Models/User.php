<?php

namespace App\Models;

use App\Enums\Role;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Imports\Models\TicketImport;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** MustVerifyEmail makes the `verified` middleware enforce email verification. */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    /**
     * role, is_available and last_assigned_at are not fillable: they change only through forceFill in a service.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** Same as the column defaults, so a new model has them without a re-query. */
    protected $attributes = [
        'role' => 'customer',
        'is_available' => true,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

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

    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_agent_id');
    }

    /** Uses the prunable App\Models\Notification instead of the framework default. */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    public function ticketReplies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    public function ticketImports(): HasMany
    {
        return $this->hasMany(TicketImport::class);
    }

    /** Users with tickets, replies or imports cannot be deleted (keeps the history intact). */
    public function hasTicketHistory(): bool
    {
        return $this->createdTickets()->exists()
            || $this->assignedTickets()->exists()
            || $this->ticketReplies()->exists()
            || $this->ticketImports()->exists()
            || Attachment::query()->where('uploaded_by', $this->getKey())->exists();
    }

    /** Categories an agent covers (used by auto-assignment). */
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

    public function scopeRole(Builder $query, Role $role): void
    {
        $query->where('role', $role);
    }

    public function scopeAgents(Builder $query): void
    {
        $query->where('role', Role::Agent);
    }

    /** Agents who can receive new tickets. */
    public function scopeAvailableAgents(Builder $query): void
    {
        $query->where('role', Role::Agent)->where('is_available', true);
    }
}
