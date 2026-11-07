<?php

namespace App\Enums;

/** What an EscalateTicketJob does to a ticket. */
enum EscalationKind: string
{
    /** Reached sla_warning_at: notify, once per deadline. */
    case Warning = 'warning';

    /** Passed due_at: notify, mark escalated (level 1), once per deadline. */
    case Breach = 'breach';

    /** Still open long after the breach: escalate to level 2, notify admins again. */
    case LevelTwo = 'level2';
}
