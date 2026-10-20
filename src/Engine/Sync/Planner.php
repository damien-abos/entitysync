<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Sync;

use GlpiPlugin\Entitysync\Engine\Tree\BuildResult;
use GlpiPlugin\Entitysync\Engine\Tree\Issue;
use GlpiPlugin\Entitysync\Engine\Tree\Node;
use GlpiPlugin\Entitysync\Engine\Tree\Path;
use GlpiPlugin\Entitysync\Engine\Util\Msg;
use GlpiPlugin\Entitysync\Engine\Util\Text;

/**
 * Compares the desired tree with GLPI and proposes operations.
 *
 * Matching uses the key path stored in the link table:
 * - linked node: update when name, comment or parent differ;
 * - unlinked node with a same-name entity under the same parent: link (adoption), or
 *   re-link when that entity was linked to a path that disappeared (key change);
 * - unlinked node otherwise: add;
 * - linked path absent from the desired tree: delete, only for entities linked by this
 *   model, blocked when the entity still has sub-entities or items.
 */
final class Planner
{
    /** @var (callable(list<int>): array<int, list<string>>)|null */
    private $usageInspector;

    /** Complete name of the root entity followed by the separator, stripped from displayed paths. */
    private string $rootPrefix = '';

    /**
     * @param int                                             $rootEntityId
     * @param (callable(list<int>): array<int, list<string>>)|null $usageInspector
     *        Returns, for each entity id, the reasons preventing its deletion (items still attached).
     * @param array<string, string>                           $forbiddenTypes
     *        Operation types the current user may not apply, with the reason.
     */
    public function __construct(
        private readonly int $rootEntityId,
        ?callable $usageInspector = null,
        private readonly array $forbiddenTypes = [],
    ) {
        $this->usageInspector = $usageInspector;
    }

    public function plan(BuildResult $build, ExistingState $state): Plan
    {
        $entities = $state->entities;
        $issues = $build->issues;
        $root = $entities[$this->rootEntityId] ?? null;
        $this->rootPrefix = $root !== null ? $root['completename'] . ' > ' : '';

        /** @var array<int, list<int>> $children */
        $children = [];
        /** @var array<int, array<string, int>> $nameIndex */
        $nameIndex = [];
        foreach ($entities as $id => $entity) {
            if ($entity['parent_id'] !== null && $id !== $this->rootEntityId) {
                $children[$entity['parent_id']][] = $id;
                $nameIndex[$entity['parent_id']][Text::normalizeName($entity['name'])] = $id;
            }
        }

        $links = [];
        $staleLinkIds = [];
        $linkByEntity = [];
        foreach ($state->links as $pathId => $link) {
            if (!isset($entities[$link['entity_id']]) || $link['entity_id'] === $this->rootEntityId) {
                $staleLinkIds[] = $link['id'];
                continue;
            }
            $links[$pathId] = $link;
            $linkByEntity[$link['entity_id']] = $pathId;
        }
        if ($staleLinkIds !== []) {
            $issues[] = Issue::warning(Msg::t(
                '%d link(s) point to entities deleted outside of this plugin; they will be cleaned up on the next application.',
                count($staleLinkIds),
            ));
        }

        /** @var array<string, Operation> $operations */
        $operations = [];
        /** @var array<string, int|null> $resolved path id => entity id (null while pending creation) */
        $resolved = [];
        /** @var array<string, string> $creatingOp path id => id of the add/link operation materializing it */
        $creatingOp = [];
        /** @var array<string, true> $relinkedPaths old path ids taken over by a key change */
        $relinkedPaths = [];

        $nodes = array_values($build->nodes);
        usort($nodes, static fn (Node $a, Node $b): int => $a->level <=> $b->level);

        foreach ($nodes as $node) {
            if ($node->parentPathId === null) {
                $parentEntityId = $this->rootEntityId;
            } elseif (array_key_exists($node->parentPathId, $resolved)) {
                $parentEntityId = $resolved[$node->parentPathId];
            } else {
                continue; // Parent excluded upstream: nothing to do.
            }
            $parentOp = $node->parentPathId !== null ? ($creatingOp[$node->parentPathId] ?? null) : null;

            $link = $links[$node->pathId] ?? null;
            if ($link !== null) {
                $entity = $entities[$link['entity_id']];
                $resolved[$node->pathId] = $entity['id'];
                $op = $this->newOperation(Operation::TYPE_UPDATE, $node, $entity['id'], $parentEntityId);
                $this->computeChanges($op, $node, $entity, $parentEntityId, $entities, $build);
                if ($op->changes !== []) {
                    if ($parentEntityId === null && $parentOp !== null) {
                        $op->requires[] = $parentOp;
                    }
                    $operations[$op->id()] = $op;
                }
                continue;
            }

            $candidateId = $parentEntityId !== null
                ? ($nameIndex[$parentEntityId][Text::normalizeName($node->name)] ?? null)
                : null;

            $previousPathId = $candidateId !== null ? ($linkByEntity[$candidateId] ?? null) : null;
            $canTakeOver = $candidateId !== null && (
                // Unmanaged entity with the same name: adopt it.
                $previousPathId === null
                // Entity managed by this model under a key that disappeared: the key changed.
                || (!isset($build->nodes[$previousPathId]) && !isset($build->protectedPaths[$previousPathId]) && !isset($relinkedPaths[$previousPathId]))
            );

            if ($canTakeOver) {
                $entity = $entities[$candidateId];
                $op = $this->newOperation(Operation::TYPE_LINK, $node, $candidateId, $parentEntityId);
                $this->computeChanges($op, $node, $entity, $parentEntityId, $entities, $build);
                if ($previousPathId !== null) {
                    $op->previousPathId = $previousPathId;
                    $relinkedPaths[$previousPathId] = true;
                }
                if (isset($state->foreignLinkedEntities[$candidateId])) {
                    $op->block(Msg::t('This entity is already managed by another import model.'));
                }
                if ($parentOp !== null) {
                    $op->requires[] = $parentOp;
                }
                $operations[$op->id()] = $op;
                $resolved[$node->pathId] = $candidateId;
                $creatingOp[$node->pathId] = $op->id();
                continue;
            }

            $op = $this->newOperation(Operation::TYPE_ADD, $node, null, $parentEntityId);
            if ($parentOp !== null) {
                $op->requires[] = $parentOp;
            }
            $operations[$op->id()] = $op;
            $resolved[$node->pathId] = null;
            $creatingOp[$node->pathId] = $op->id();
        }

        $deletions = $this->planDeletions($build, $links, $relinkedPaths, $entities, $children, $operations);

        $this->checkNameCollisions($operations, $entities, $deletions);
        $this->applyPermissions($operations);
        $this->propagateBlocks($operations);

        return new Plan($operations, $issues, $build, $staleLinkIds);
    }

    private function newOperation(string $type, Node $node, ?int $entityId, ?int $parentEntityId): Operation
    {
        return new Operation(
            type: $type,
            pathId: $node->pathId,
            level: $node->level,
            displayPath: $node->displayPath(),
            name: $node->name,
            comment: $node->comment,
            entityId: $entityId,
            parentPathId: $node->parentPathId,
            parentEntityId: $parentEntityId,
            line: $node->firstLine,
        );
    }

    /**
     * @param array{id: int, name: string, comment: string|null, parent_id: int|null, completename: string} $entity
     * @param array<int, array{id: int, name: string, comment: string|null, parent_id: int|null, completename: string}> $entities
     */
    private function computeChanges(
        Operation $op,
        Node $node,
        array $entity,
        ?int $parentEntityId,
        array $entities,
        BuildResult $build,
    ): void {
        if ($entity['name'] !== $node->name) {
            $op->changes[] = ['field' => Operation::FIELD_NAME, 'old' => $entity['name'], 'new' => $node->name];
        }

        if (
            $node->comment !== null
            && Text::normalizeMultiline($entity['comment']) !== Text::normalizeMultiline($node->comment)
        ) {
            $op->changes[] = [
                'field' => Operation::FIELD_COMMENT,
                'old'   => Text::normalizeMultiline($entity['comment']),
                'new'   => $node->comment,
            ];
        }

        if ($parentEntityId === null || $entity['parent_id'] !== $parentEntityId) {
            $old = $this->relativePath($entities[$entity['parent_id'] ?? -1]['completename'] ?? '');
            if ($parentEntityId !== null) {
                $new = $this->relativePath($entities[$parentEntityId]['completename'] ?? '');
            } else {
                $parent = $build->nodes[$node->parentPathId ?? ''] ?? null;
                $new = $parent !== null ? Msg::t('%s (new)', $parent->displayPath()) : '';
            }
            $op->changes[] = ['field' => Operation::FIELD_PARENT, 'old' => $old, 'new' => $new];
        }
    }

    /**
     * @param array<string, array{id: int, entity_id: int}> $links
     * @param array<string, true> $relinkedPaths
     * @param array<int, array{id: int, name: string, comment: string|null, parent_id: int|null, completename: string}> $entities
     * @param array<int, list<int>> $children
     * @param array<string, Operation> $operations
     *
     * @return array<int, Operation> Delete operations keyed by entity id.
     */
    private function planDeletions(
        BuildResult $build,
        array $links,
        array $relinkedPaths,
        array $entities,
        array $children,
        array &$operations,
    ): array {
        /** @var array<int, Operation> $deletions */
        $deletions = [];
        foreach ($links as $pathId => $link) {
            if (isset($build->nodes[$pathId]) || isset($build->protectedPaths[$pathId]) || isset($relinkedPaths[$pathId])) {
                continue;
            }
            $entity = $entities[$link['entity_id']];
            $level = max(0, count(Path::segments($pathId)) - 1);
            $deletions[$entity['id']] = new Operation(
                type: Operation::TYPE_DELETE,
                pathId: $pathId,
                level: $level,
                displayPath: $this->relativePath($entity['completename']),
                name: $entity['name'],
                comment: $entity['comment'],
                entityId: $entity['id'],
                parentPathId: null,
                parentEntityId: $entity['parent_id'],
                line: null,
            );
        }

        if ($deletions === []) {
            return [];
        }

        $usage = $this->usageInspector !== null ? ($this->usageInspector)(array_keys($deletions)) : [];

        // Deepest first so that blocks propagate from children to parents.
        $ordered = $deletions;
        uasort($ordered, static fn (Operation $a, Operation $b): int => $b->level <=> $a->level);

        foreach ($ordered as $entityId => $op) {
            $foreignChildren = 0;
            foreach ($children[$entityId] ?? [] as $childId) {
                if (isset($deletions[$childId])) {
                    $op->requires[] = $deletions[$childId]->id();
                    if ($deletions[$childId]->isBlocked()) {
                        $op->block(Msg::t('A sub-entity cannot be deleted.'));
                    }
                } else {
                    $foreignChildren++;
                }
            }
            if ($foreignChildren > 0) {
                $op->block(Msg::t('Contains %d sub-entity(ies) not proposed for deletion.', $foreignChildren));
            }
            foreach ($usage[$entityId] ?? [] as $reason) {
                $op->block($reason);
            }
            $operations[$op->id()] = $op;
        }

        return $deletions;
    }

    /**
     * Blocks operations whose final name collides with an entity that stays in place.
     *
     * @param array<string, Operation> $operations
     * @param array<int, array{id: int, name: string, comment: string|null, parent_id: int|null, completename: string}> $entities
     * @param array<int, Operation> $deletions
     */
    private function checkNameCollisions(array $operations, array $entities, array $deletions): void
    {
        // Entities whose name/parent will change are not "staying in place".
        $moving = [];
        foreach ($operations as $op) {
            if ($op->entityId !== null && ($op->hasChange(Operation::FIELD_NAME) || $op->hasChange(Operation::FIELD_PARENT))) {
                $moving[$op->entityId] = true;
            }
        }

        $staying = [];
        foreach ($entities as $id => $entity) {
            if ($id === $this->rootEntityId || $entity['parent_id'] === null || isset($deletions[$id]) || isset($moving[$id])) {
                continue;
            }
            $staying[$entity['parent_id']][Text::normalizeName($entity['name'])] = $id;
        }

        foreach ($operations as $op) {
            if ($op->type === Operation::TYPE_DELETE || $op->parentEntityId === null) {
                continue;
            }
            if ($op->type !== Operation::TYPE_ADD && !isset($moving[$op->entityId ?? -1])) {
                continue;
            }
            $other = $staying[$op->parentEntityId][Text::normalizeName($op->name)] ?? null;
            if ($other !== null && $other !== $op->entityId) {
                $op->block(Msg::t(
                    'The name is already used by the entity "%s" under the same parent.',
                    $this->relativePath($entities[$other]['completename']),
                ));
            }
        }
    }

    /**
     * @param array<string, Operation> $operations
     */
    private function applyPermissions(array $operations): void
    {
        foreach ($operations as $op) {
            $type = $op->type === Operation::TYPE_LINK && $op->changes === [] ? 'link_only' : $op->type;
            if (isset($this->forbiddenTypes[$type])) {
                $op->block($this->forbiddenTypes[$type]);
            }
        }
    }

    /**
     * Blocks operations that require a blocked operation.
     *
     * @param array<string, Operation> $operations
     */
    private function propagateBlocks(array $operations): void
    {
        do {
            $changed = false;
            foreach ($operations as $op) {
                if ($op->isBlocked() || $op->type === Operation::TYPE_DELETE) {
                    continue;
                }
                foreach ($op->requires as $required) {
                    if (isset($operations[$required]) && $operations[$required]->isBlocked()) {
                        $op->block(Msg::t('Depends on a blocked operation (%s).', $operations[$required]->displayPath));
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
    }

    /**
     * Strips the root entity prefix from a GLPI complete name for display.
     */
    private function relativePath(string $completename): string
    {
        if ($this->rootPrefix !== '' && str_starts_with($completename, $this->rootPrefix)) {
            return substr($completename, strlen($this->rootPrefix));
        }

        return $completename;
    }
}
