<?php

namespace App\Enums;

enum ActivityType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case CategoryChanged = 'category_changed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Replied = 'replied';
    case InternalNoteAdded = 'internal_note_added';
    case AttachmentAdded = 'attachment_added';
    case SlaWarning = 'sla_warning';
    case SlaBreached = 'sla_breached';
    case Escalated = 'escalated';
    case Imported = 'imported';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->ucfirst()->toString();
    }

    /** Activities raised by the system (scheduler/jobs) rather than a person. */
    public function isSystem(): bool
    {
        return in_array($this, [self::SlaWarning, self::SlaBreached, self::Escalated, self::Imported], true);
    }
}
