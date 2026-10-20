<?php

/**
 * Entity Sync plugin for GLPI
 *
 * Synchronizes the entity tree below a target entity from a CSV file.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Entitysync\Engine\Util\Msg;
use GlpiPlugin\Entitysync\Model;
use GlpiPlugin\Entitysync\ProfileRights;

define('PLUGIN_ENTITYSYNC_VERSION', '0.0.1');

// Minimal GLPI version, inclusive.
define('PLUGIN_ENTITYSYNC_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive.
define('PLUGIN_ENTITYSYNC_MAX_GLPI', '11.0.99');

/**
 * Plugin initialization (hooks registration).
 */
function plugin_init_entitysync(): void
{
    global $PLUGIN_HOOKS;

    // Engine messages are translated with the plugin domain.
    Msg::setTranslator(static fn (string $text): string => __($text, 'entitysync'));

    Plugin::registerClass(ProfileRights::class, ['addtabon' => ['Profile']]);

    if (Model::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['entitysync'] = ['admin' => Model::class];
    }

    $PLUGIN_HOOKS[Hooks::ADD_CSS]['entitysync'] = ['entitysync.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['entitysync'] = ['entitysync.js'];

    // Keep the link table consistent when an entity is purged outside the plugin.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['entitysync'] = [Entity::class => 'plugin_entitysync_item_purge_entity'];
}

/**
 * Plugin description and requirements.
 *
 * @return array<string, mixed>
 */
function plugin_version_entitysync(): array
{
    return [
        'name'         => 'Entity Sync',
        'version'      => PLUGIN_ENTITYSYNC_VERSION,
        'author'       => 'Damien',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ENTITYSYNC_MIN_GLPI,
                'max' => PLUGIN_ENTITYSYNC_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
                'exts' => [
                    'mbstring' => ['required' => true],
                    'intl'     => ['required' => true],
                ],
            ],
        ],
    ];
}

/**
 * Checks prerequisites before install.
 */
function plugin_entitysync_check_prerequisites(): bool
{
    return true;
}

/**
 * Checks configuration.
 */
function plugin_entitysync_check_config(bool $verbose = false): bool
{
    return true;
}
