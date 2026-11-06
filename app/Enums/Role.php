<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Agent => 'Agent',
            self::Customer => 'Customer',
        };
    }

    /** Staff can see and work on every ticket; customers only their own. */
    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }
}
