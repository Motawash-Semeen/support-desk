<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Tailwind colour name used by priority badges. */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'blue',
            self::High => 'orange',
            self::Critical => 'red',
        };
    }

    /** Higher means more urgent; used for sorting and escalation ordering. */
    public function sortWeight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    /** Default resolution target in business minutes, from config/support.php. */
    public function defaultSlaMinutes(): int
    {
        return (int) config("support.sla.{$this->value}");
    }
}
