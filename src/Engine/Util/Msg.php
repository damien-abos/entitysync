<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Util;

/**
 * Message formatter used by the engine.
 *
 * The engine does not depend on GLPI; the plugin bootstrap plugs GLPI's gettext
 * function in through {@see Msg::setTranslator()} so that engine messages are
 * translated with the plugin domain.
 */
final class Msg
{
    /** @var (callable(string): string)|null */
    private static $translator = null;

    /**
     * @param (callable(string): string)|null $translator
     */
    public static function setTranslator(?callable $translator): void
    {
        self::$translator = $translator;
    }

    /**
     * Translates a format string, then applies `sprintf()` with the arguments.
     */
    public static function t(string $format, string|int|float ...$args): string
    {
        $translated = self::$translator !== null ? (self::$translator)($format) : $format;

        return $args === [] ? $translated : vsprintf($translated, $args);
    }
}
