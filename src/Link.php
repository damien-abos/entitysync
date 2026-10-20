<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use CommonDBTM;

/**
 * Link between a model key path and the GLPI entity it manages.
 *
 * Only entities present in this table can be proposed for deletion.
 */
class Link extends CommonDBTM
{
    public static $rightname = 'plugin_entitysync_model';

    public static function getTypeName($nb = 0)
    {
        return _n('Managed entity', 'Managed entities', $nb, 'entitysync');
    }

    /**
     * Number of entities managed by a model.
     */
    public static function countForModel(int $modelId): int
    {
        return countElementsInTable(self::getTable(), ['plugin_entitysync_models_id' => $modelId]);
    }
}
