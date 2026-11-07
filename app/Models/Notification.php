<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app notification model (database channel), made prunable: read
 * notifications older than support.notifications.prune_read_after_days are
 * deleted by `model:prune`. Unread ones are kept however old.
 * Wired in through User::notifications().
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
