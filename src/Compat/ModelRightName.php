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
     * Right of the plugin's items (`CommonGLPI::$rightname`).
     */
    trait ModelRightName
    {
        public static string $rightname = 'plugin_entitysync_model';
    }
} else {
    /**
     * Right of the plugin's items (`CommonGLPI::$rightname`).
     */
    trait ModelRightName
    {
        public static $rightname = 'plugin_entitysync_model';
    }
}
