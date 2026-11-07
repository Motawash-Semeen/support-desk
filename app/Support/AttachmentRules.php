<?php

namespace App\Support;

use App\Rules\SafeUpload;

/**
 * Shared upload rules for every form that accepts attachments, built from
 * config('support.attachments'):
 *
 *  - at most `max_files` files, each at most `max_kb` KB;
 *  - `extensions`: the client-side extension must be on the whitelist;
 *  - `mimes`: the extension guessed from the file CONTENT (finfo) must also
 *    be on the whitelist, so a renamed executable fails;
 *  - SafeUpload: no empty files, no executable extension anywhere in the name.
 *
 * Whitelist by design: php, phtml, exe, sh, js, html, svg ... are never listed.
 */
final class AttachmentRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $field = 'attachments'): array
    {
        $config = config('support.attachments');
        $allowed = implode(',', $config['mimes']);

        return [
            $field => ['nullable', 'array', 'max:'.(int) $config['max_files']],
            "{$field}.*" => [
                'required',
                'file',
                new SafeUpload,
                'max:'.(int) $config['max_kb'],
                'extensions:'.$allowed,
                'mimes:'.$allowed,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(string $field = 'attachments'): array
    {
        return ["{$field}.*" => 'attachment'];
    }
}
