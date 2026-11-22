<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Compat;

// Declared according to the GLPI version: see Glpi.

if (Glpi::isAtLeast12()) {
    /**
     * Profile right, for the rights tab on profiles (`CommonGLPI::$rightname`).
     */
    trait ProfileRightName
    {
        public static string $rightname = 'profile';
    }
} else {
    /**
     * Profile right, for the rights tab on profiles (`CommonGLPI::$rightname`).
     */
    trait ProfileRightName
    {
        public static $rightname = 'profile';
    }
}
