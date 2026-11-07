<?php

namespace App\Modules\Sla\Models;

use App\Modules\Sla\Observers\SlaRulesObserver;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Ticket;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(SlaRulesObserver::class)]
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'priority',
        'resolution_minutes',
        'warning_threshold',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'warning_threshold' => 0.80,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'resolution_minutes' => 'integer',
            'warning_threshold' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Served by the unique index on priority. */
    public function scopeForPriority(Builder $query, TicketPriority $priority): void
    {
        $query->where('priority', $priority);
    }
}
