<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Sync;

/**
 * Write access to GLPI entities and to the link table, used by the applier.
 *
 * Implementations throw {@see \RuntimeException} with a user-readable message on failure.
 */
interface GatewayInterface
{
    /**
     * Creates an entity and returns its id.
     */
    public function createEntity(string $name, ?string $comment, int $parentId): int;

    /**
     * Updates an entity.
     *
     * @param array{name?: string, comment?: string, entities_id?: int} $fields
     */
    public function updateEntity(int $entityId, array $fields): void;

    /**
     * Permanently deletes an entity.
     */
    public function deleteEntity(int $entityId): void;

    /**
     * Creates or replaces the link of a path for the model.
     */
    public function saveLink(string $pathId, int $entityId): void;

    /**
     * Removes the link of a path for the model.
     */
    public function removeLink(string $pathId): void;

    /**
     * Removes links by id (stale links).
     *
     * @param list<int> $linkIds
     */
    public function removeLinksById(array $linkIds): void;
}
