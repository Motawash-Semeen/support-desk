<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One distinct error tracked by App\Services\ErrorTracking\ErrorRecorder.
 * Rows are written by the recorder (bulk upsert), never through forms.
 */
class ErrorLog extends Model
{
    use Prunable;

    /** Severity order for display and filtering (Monolog level names). */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice'];

    /** @var list<string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'line' => 'integer',
            'status_code' => 'integer',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** @param Builder<ErrorLog> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @param Builder<ErrorLog> $query */
    public function scopeResolved(Builder $query): void
    {
        $query->whereNotNull('resolved_at');
    }

    /** Badge colour per level (see components/badge). */
    public function levelColor(): string
    {
        return match ($this->level) {
            'emergency', 'alert', 'critical' => 'red',
            'error' => 'orange',
            'warning' => 'amber',
            default => 'blue',
        };
    }

    /**
     * Resolved errors go after support.error_tracker.retention_days; anything
     * not seen for three times that long goes too.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('support.error_tracker.retention_days');

        return static::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $r) => $r->whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays($days)))
            ->orWhere('last_seen_at', '<', now()->subDays($days * 3)));
    }
}
