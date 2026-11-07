<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 *
 * JSON view of a ticket. The controller sets `visibleReplies`,
 * `visibleActivities` and `ticketAttachments` to collections ALREADY filtered
 * for the viewer (repliesVisibleTo / activitiesVisibleTo); the resource never
 * reads the unfiltered `replies` / `activities` relations, so it cannot leak
 * internal notes.
 */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ticket_number' => $this->ticket_number,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'category' => $this->category?->name,
            'assignee' => $this->assignee?->name,
            'sla_status' => $this->slaStatus()?->value,
            'due_at' => $this->due_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => AttachmentResource::collection($this->whenLoaded('ticketAttachments')),
            'replies' => TicketReplyResource::collection($this->whenLoaded('visibleReplies')),
            'activity' => TicketActivityResource::collection($this->whenLoaded('visibleActivities')),
        ];
    }
}
