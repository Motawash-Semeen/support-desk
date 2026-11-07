<?php

namespace App\Modules\Activity\Enums;

/**
 * Every kind of entry in a ticket's append-only history. Values are what the
 * ticket_activities.type column stores.
 */
enum ActivityType: string
{
    case TicketCreated = 'ticket_created';
    /** Subject or description edited (not in the brief's list; needed so every edit is traceable). */
    case TicketUpdated = 'ticket_updated';
    case StatusChanged = 'status_changed';
    case Reopened = 'reopened';
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case Released = 'released';
    case AutoAssignSkipped = 'auto_assign_skipped';
    case CustomerReplied = 'customer_replied';
    case AgentReplied = 'agent_replied';
    case InternalNoteAdded = 'internal_note_added';
    case AttachmentAdded = 'attachment_added';
    case SlaRecalculated = 'sla_recalculated';
    case SlaWarning = 'sla_warning';
    case SlaBreached = 'sla_breached';
    case Escalated = 'escalated';
    case Imported = 'imported';

    public function label(): string
    {
        return match ($this) {
            self::TicketCreated => 'Ticket created',
            self::TicketUpdated => 'Ticket edited',
            self::StatusChanged => 'Status changed',
            self::Reopened => 'Reopened',
            self::PriorityChanged => 'Priority changed',
            self::CategoryChanged => 'Category changed',
            self::Assigned => 'Assigned',
            self::Reassigned => 'Reassigned',
            self::Released => 'Released to queue',
            self::AutoAssignSkipped => 'Auto-assignment skipped',
            self::CustomerReplied => 'Customer replied',
            self::AgentReplied => 'Agent replied',
            self::InternalNoteAdded => 'Internal note added',
            self::AttachmentAdded => 'Attachment added',
            self::SlaRecalculated => 'SLA recalculated',
            self::SlaWarning => 'SLA warning',
            self::SlaBreached => 'SLA breached',
            self::Escalated => 'Escalated',
            self::Imported => 'Imported',
        };
    }

    /**
     * Whether a customer may see this entry in their ticket's timeline.
     * Internal: notes, assignment details, SLA internals and escalation.
     * The match is exhaustive, so a new case cannot skip this decision.
     */
    public function isCustomerVisible(): bool
    {
        return match ($this) {
            self::TicketCreated,
            self::TicketUpdated,
            self::StatusChanged,
            self::Reopened,
            self::PriorityChanged,
            self::CategoryChanged,
            self::CustomerReplied,
            self::AgentReplied,
            self::AttachmentAdded,
            self::Imported => true,
            self::Assigned,
            self::Reassigned,
            self::Released,
            self::AutoAssignSkipped,
            self::InternalNoteAdded,
            self::SlaRecalculated,
            self::SlaWarning,
            self::SlaBreached,
            self::Escalated => false,
        };
    }

    /** @return list<string> */
    public static function customerVisibleValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->isCustomerVisible()),
        ));
    }
}
