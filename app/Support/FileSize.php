<?php

namespace App\Support;

/**
 * Human-readable byte sizes without the intl extension
 * (Illuminate\Support\Number::fileSize() requires intl).
 */
final class FileSize
{
    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = max(0, $bytes);
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $size : number_format($size, $size >= 10 ? 0 : 1)).' '.$units[$unit];
    }
}
