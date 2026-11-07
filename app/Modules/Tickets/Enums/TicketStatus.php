<?php

namespace App\Modules\Tickets\Enums;

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
     * The single source of truth for status changes. Reopening a resolved
     * ticket goes back to In progress; Closed is terminal except for the
     * admin-only reopen in adminOnlyTransitions().
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::WaitingForCustomer, self::Resolved],
            self::InProgress => [self::WaitingForCustomer, self::Resolved, self::Open],
            // -> open: the customer replied to an unassigned ticket (back to the queue).
            self::WaitingForCustomer => [self::InProgress, self::Resolved, self::Closed, self::Open],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [],
        };
    }

    /**
     * Extra transitions only an administrator may perform.
     *
     * @return list<self>
     */
    public function adminOnlyTransitions(): array
    {
        return match ($this) {
            self::Closed => [self::InProgress],
            default => [],
        };
    }

    public function canTransitionTo(self $next, bool $byAdmin = false): bool
    {
        return in_array($next, $this->allowedTransitions(), true)
            || ($byAdmin && in_array($next, $this->adminOnlyTransitions(), true));
    }

    /** Status a reopened ticket returns to. */
    public static function reopenTarget(): self
    {
        return self::InProgress;
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
