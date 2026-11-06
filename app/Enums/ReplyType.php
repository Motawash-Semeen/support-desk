<?php

namespace App\Enums;

enum ReplyType: string
{
    /** Visible to the customer and staff. */
    case Public = 'public';

    /** Visible to staff only. */
    case InternalNote = 'internal_note';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Reply',
            self::InternalNote => 'Internal note',
        };
    }

    public function isVisibleToCustomer(): bool
    {
        return $this === self::Public;
    }
}
