<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Compat;

/**
 * Differences between the supported GLPI major versions (11.0, 12.0).
 *
 * GLPI 12 declares native types on the properties of its base classes
 * (`CommonGLPI::$rightname`, `CommonDBTM::$dohistory`...). A child class must
 * redeclare such a property with the same type, while GLPI 11 forbids a type on it:
 * the plugin redeclares them through the traits of this namespace, whose declaration
 * depends on the running GLPI version.
 *
 * GLPI 12 also replaces the CSRF tokens by a check of the `Sec-Fetch-Site` / `Origin`
 * request headers, and deprecates `csrf_token()`.
 */
final class Glpi
{
    /** First GLPI version with typed properties and header-based CSRF checks. */
    private const V12 = '12.0.0-dev';

    public static function isAtLeast12(): bool
    {
        return version_compare(GLPI_VERSION, self::V12, '>=');
    }

    /**
     * Whether POST forms and AJAX requests must carry a CSRF token (GLPI 11).
     */
    public static function needsCsrfToken(): bool
    {
        return !self::isAtLeast12();
    }
}
