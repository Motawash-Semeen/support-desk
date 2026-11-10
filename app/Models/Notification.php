<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app notification, prunable: read ones older than
 * support.notifications.prune_read_after_days are deleted by model:prune.
 */
class Notification extends DatabaseNotification
{
    use Prunable;

    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays((int) config('support.notifications.prune_read_after_days')));
    }
}
