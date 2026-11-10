<?php

namespace App\Modules\Sla\Models;

use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'priority',
        'resolution_minutes',
        'warning_threshold',
        'is_active',
    ];

    protected $attributes = [
        'warning_threshold' => 0.80,
        'is_active' => true,
    ];

    /** A change clears the SLA rule cache after the commit. */
    protected static function booted(): void
    {
        static::saved(fn () => SlaService::forgetRulesAfterCommit());
        static::deleted(fn () => SlaService::forgetRulesAfterCommit());
    }

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'resolution_minutes' => 'integer',
            'warning_threshold' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForPriority(Builder $query, TicketPriority $priority): void
    {
        $query->where('priority', $priority);
    }
}
