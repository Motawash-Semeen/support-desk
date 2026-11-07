<?php

namespace App\Support;

/**
 * Turns free text into a safe MySQL BOOLEAN MODE expression: every word is
 * required and prefix-matched ("+print* +jam*"). Boolean operators typed by the
 * user (+ - < > ( ) ~ * " @) are stripped, so input cannot change the query's
 * meaning; the result is still passed as a bound parameter.
 *
 * Words shorter than InnoDB's default innodb_ft_min_token_size (3) are not in
 * the index and are dropped; null means nothing searchable remains.
 */
final class FullTextQuery
{
    public const MIN_WORD_LENGTH = 3;

    public const MAX_WORDS = 8;

    public static function boolean(string $input): ?string
    {
        $words = array_values(array_filter(
            self::words($input),
            fn (string $word) => mb_strlen($word) >= self::MIN_WORD_LENGTH,
        ));

        if ($words === []) {
            return null;
        }

        return implode(' ', array_map(
            fn (string $word) => '+'.$word.'*',
            array_slice($words, 0, self::MAX_WORDS),
        ));
    }

    /** @return list<string> letters (with their combining marks), digits and underscores only */
    public static function words(string $input): array
    {
        $clean = preg_replace('/[^\p{L}\p{M}\p{N}_]+/u', ' ', $input) ?? '';

        return preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Escape LIKE wildcards so user input matches literally. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
