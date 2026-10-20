<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Util;

/**
 * String helpers shared by the engine.
 */
final class Text
{
    /**
     * Normalizes a name the way the `utf8mb4_unicode_ci` collation compares it:
     * case-insensitive, accent-insensitive, trailing spaces ignored.
     *
     * GLPI enforces a UNIQUE (entities_id, name) index on `glpi_entities`, so two
     * names that are equal under this normalization would collide in the database.
     */
    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $name = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * Collapses internal whitespace and trims the value.
     */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Normalizes line endings and trims a multi-line text (used for comments).
     */
    public static function normalizeMultiline(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Builds a PCRE pattern from a user-supplied expression without delimiters.
     *
     * @param string $expression Expression written without delimiters (e.g. `^ORG-(\d+)$`).
     * @param string $flags      Optional modifiers among `i`, `m`, `s`, `x`, `U`.
     */
    public static function buildRegex(string $expression, string $flags = ''): string
    {
        $allowed = '';
        foreach (str_split($flags) as $flag) {
            if (str_contains('imsxU', $flag) && !str_contains($allowed, $flag)) {
                $allowed .= $flag;
            }
        }

        return '~' . str_replace('~', '\\~', $expression) . '~u' . $allowed;
    }

    /**
     * Checks that a user-supplied expression compiles.
     *
     * @return string|null Error message, or null when the expression is valid.
     */
    public static function validateRegex(string $expression, string $flags = ''): ?string
    {
        if ($expression === '') {
            return 'empty expression';
        }

        $error = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$error): bool {
            $error = $errstr;
            return true;
        });
        try {
            $result = preg_match(self::buildRegex($expression, $flags), '');
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            return $error ?? preg_last_error_msg();
        }

        return null;
    }
}
