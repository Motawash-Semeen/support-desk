<?php

namespace App\Modules\Conversation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Extra checks the built-in rules do not cover:
 *  - empty (0-byte) files;
 *  - executable extensions hidden in the middle of the name, e.g.
 *    "invoice.php.pdf" (some servers execute by the first known extension).
 * "invoice.pdf.exe" is already rejected by the extension whitelist.
 */
final class SafeUpload implements ValidationRule
{
    /** Extensions that must never appear anywhere in an uploaded file name. */
    public const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'dll', 'sh', 'bash', 'ps1', 'vbs',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'svgz',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jar',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if ((int) $value->getSize() === 0) {
            $fail(__('The :attribute is empty.'));

            return;
        }

        $segments = explode('.', strtolower($value->getClientOriginalName()));
        array_shift($segments); // the base name

        if (array_intersect($segments, self::EXECUTABLE_EXTENSIONS) !== []) {
            $fail(__('The :attribute has a forbidden file name.'));
        }
    }
}
