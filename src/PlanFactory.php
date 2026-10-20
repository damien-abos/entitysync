<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use GlpiPlugin\Entitysync\Engine\Csv\CsvData;
use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Engine\Sync\Plan;
use GlpiPlugin\Entitysync\Engine\Sync\Planner;
use GlpiPlugin\Entitysync\Engine\Tree\TreeBuilder;

/**
 * Computes the plan of an import against the current GLPI state.
 */
final class PlanFactory
{
    /**
     * @param array<string, string> $forbiddenTypes Operation types the user may not apply, with the reason.
     *
     * @throws EngineException When the configuration does not match the file.
     */
    public static function compute(Model $model, CsvData $csv, array $forbiddenTypes): Plan
    {
        $config = $model->toEngineConfig();
        $errors = $config->validate($csv->columns);
        if ($errors !== []) {
            throw new EngineException(implode("\n", $errors));
        }

        $gateway = new GlpiGateway($model->getID());
        $build = (new TreeBuilder($config))->build($csv);
        $planner = new Planner($config->rootEntityId, $gateway->inspectUsage(...), $forbiddenTypes);

        return $planner->plan($build, $gateway->loadState($config->rootEntityId));
    }
}
