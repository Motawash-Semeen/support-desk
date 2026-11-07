<?php

namespace App\Modules\Conversation\Support;

use Illuminate\Support\Str;

/**
 * Cleans a user-supplied file name before it is stored or echoed back in a
 * Content-Disposition header: no directories, no control characters, no
 * characters that are special in headers or file systems, bounded length,
 * extension preserved. It is never used as a storage path.
 */
final class SafeFilename
{
    public const MAX_LENGTH = 150;

    public static function clean(string $name, string $fallback = 'attachment'): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/[\/:*?"<>|;]+/', '_', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '', ' .');

        if ($name === '') {
            return $fallback;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $suffix = $extension === '' ? '' : '.'.Str::limit($extension, 10, '');

        return Str::limit($base, self::MAX_LENGTH - strlen($suffix), '').$suffix;
    }
}
