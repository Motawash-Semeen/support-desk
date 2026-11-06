<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::WaitingForCustomer => 'Waiting for customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** Tailwind colour name used by status badges. */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::InProgress => 'indigo',
            self::WaitingForCustomer => 'amber',
            self::Resolved => 'green',
            self::Closed => 'gray',
        };
    }

    /** Active tickets are still being worked on, so their SLA clock matters. */
    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    /**
     * Statuses this status may move to. Resolved and closed tickets can be
     * reopened (back to Open); everything else moves forward.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::WaitingForCustomer, self::Resolved, self::Closed],
            self::InProgress => [self::WaitingForCustomer, self::Resolved, self::Closed],
            self::WaitingForCustomer => [self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::Closed, self::Open],
            self::Closed => [self::Open],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<self> */
    public static function activeCases(): array
    {
        return [self::Open, self::InProgress, self::WaitingForCustomer];
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::activeCases());
    }

    /** @return list<string> */
    public static function finishedValues(): array
    {
        return [self::Resolved->value, self::Closed->value];
    }
}
