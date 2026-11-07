<?php

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Services\TicketActivityRecorder;
use App\Modules\Sla\Services\TicketSlaRecalculator;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Events\TicketUpdated;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edits ticket fields. Which fields a user may send is decided by
 * UpdateTicketRequest (through the policy); this Action records one activity
 * per changed field and recalculates the SLA when the priority changes.
 */
final class UpdateTicket
{
    private const EDITABLE = ['subject', 'description', 'priority', 'category_id'];

    public function __construct(
        private readonly TicketSlaRecalculator $recalculator,
        private readonly TicketActivityRecorder $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $actor, Ticket $ticket, array $data): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket, $data): Ticket {
            // Lock so the recorded "old" values are the ones actually replaced.
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            $input = Arr::only($data, self::EDITABLE);
            if (array_key_exists('category_id', $input)) {
                $input['category_id'] = (int) $input['category_id'];
            }

            $locked->fill($input);

            if (! $locked->isDirty()) {
                return $locked;
            }

            $changes = [];
            foreach (array_keys($locked->getDirty()) as $field) {
                $changes[$field] = ['old' => $locked->getRawOriginal($field), 'new' => $locked->getAttributes()[$field]];
            }

            $locked->save();

            foreach ($changes as $field => $change) {
                $this->recordChange($actor, $locked, $field, $change['old'], $change['new']);
            }

            // New priority, new target, counted from the ORIGINAL created_at:
            // business time already spent still counts against the SLA.
            if (isset($changes['priority']) && $this->recalculator->apply($locked, $locked->created_at, $actor, 'priority changed')) {
                $locked->save();
            }

            TicketUpdated::dispatch($locked, $actor, $changes);

            return $locked;
        });
    }

    private function recordChange(User $actor, Ticket $ticket, string $field, mixed $old, mixed $new): void
    {
        [$type, $description] = match ($field) {
            'priority' => [
                ActivityType::PriorityChanged,
                sprintf('Priority changed from %s to %s', TicketPriority::from($old)->label(), TicketPriority::from($new)->label()),
            ],
            'category_id' => [ActivityType::CategoryChanged, $this->categoryDescription((int) $old, (int) $new)],
            'subject' => [ActivityType::TicketUpdated, 'Subject changed'],
            default => [ActivityType::TicketUpdated, 'Description edited'],
        };

        $this->activity->record($ticket, $type, $actor, $description, [$field => $old], [$field => $new]);
    }

    private function categoryDescription(int $old, int $new): string
    {
        $names = Category::query()->whereKey([$old, $new])->pluck('name', 'id');

        return sprintf('Category changed from %s to %s', $names[$old] ?? "#{$old}", $names[$new] ?? "#{$new}");
    }
}
