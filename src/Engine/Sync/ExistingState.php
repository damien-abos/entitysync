<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Sync;

/**
 * Snapshot of GLPI data needed to compute a plan.
 */
final class ExistingState
{
    /**
     * @param array<int, array{id: int, name: string, comment: string|null, parent_id: int|null, completename: string}> $entities
     *        Root entity and all its descendants, keyed by id.
     * @param array<string, array{id: int, entity_id: int}> $links
     *        Links of the current model keyed by path id.
     * @param array<int, true> $foreignLinkedEntities
     *        Entities linked by other models.
     */
    public function __construct(
        public readonly array $entities,
        public readonly array $links,
        public readonly array $foreignLinkedEntities = [],
    ) {}
}
