<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The business calendar found no working time within the search window
 * (no working weekdays, or every day for a year is a holiday). A
 * configuration error, so it is reported, never swallowed.
 */
class NoBusinessHoursConfigured extends RuntimeException
{
    public static function within(int $days): self
    {
        return new self("No business hours found within {$days} days. Check the business hours and holidays configuration.");
    }
}
