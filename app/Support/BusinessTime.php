<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Display helper: timestamps are stored in UTC and converted to the business
 * timezone (config('support.timezone')) only when rendered.
 */
final class BusinessTime
{
    public static function zone(): string
    {
        return (string) config('support.timezone');
    }

    public static function format(?DateTimeInterface $at, string $format = 'd M Y, H:i'): string
    {
        if ($at === null) {
            return '—';
        }

        return CarbonImmutable::instance($at)->setTimezone(self::zone())->format($format);
    }
}
