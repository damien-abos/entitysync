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
     * Changes of the item are recorded in its history (`CommonDBTM::$dohistory`).
     */
    trait WithHistory
    {
        public bool $dohistory = true;
    }
} else {
    /**
     * Changes of the item are recorded in its history (`CommonDBTM::$dohistory`).
     */
    trait WithHistory
    {
        public $dohistory = true;
    }
}
