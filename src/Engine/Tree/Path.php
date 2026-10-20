<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Tree;

use GlpiPlugin\Entitysync\Engine\Config\LevelConfig;

/**
 * Helpers for key paths.
 *
 * A path is the list of level keys from level 1 to a node. Its canonical identifier
 * is its JSON encoding, and the database stores the SHA-1 of that identifier.
 */
final class Path
{
    /** Key used for an empty value in the middle of a path. */
    public const PLACEHOLDER_KEY = "\u{0}empty";

    /**
     * @param list<string> $segments
     */
    public static function id(array $segments): string
    {
        return json_encode(array_values($segments), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function hash(string $pathId): string
    {
        return sha1($pathId);
    }

    /**
     * @return list<string>
     */
    public static function segments(string $pathId): array
    {
        $decoded = json_decode($pathId, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * Human-readable rendering of a key path, for messages.
     *
     * @param list<string> $segments
     */
    public static function displayKeys(array $segments, string $placeholderLabel = '∅'): string
    {
        return implode(' > ', array_map(
            static fn (string $s): string => $s === self::PLACEHOLDER_KEY
                ? $placeholderLabel
                : str_replace(LevelConfig::KEY_PART_SEPARATOR, ' + ', $s),
            $segments,
        ));
    }
}
