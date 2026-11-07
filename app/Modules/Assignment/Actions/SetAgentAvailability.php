<?php

namespace App\Modules\Assignment\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * An agent switches themselves in or out of auto-assignment. Tickets they
 * already hold stay with them; unavailable agents can still accept manually.
 */
final class SetAgentAvailability
{
    public function __invoke(User $agent, bool $available): User
    {
        if (! $agent->isAgent()) {
            throw new InvalidArgumentException('Only agents have an availability setting.');
        }

        // is_available is not mass assignable by design (D-026).
        $agent->forceFill(['is_available' => $available])->save();

        Log::info('Agent availability changed.', ['agent_id' => $agent->id, 'is_available' => $available]);

        return $agent;
    }
}
