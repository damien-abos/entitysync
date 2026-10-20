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

/**
 * Set of proposed operations with the context needed to display and apply them.
 */
final class Plan
{
    /**
     * @param array<string, Operation> $operations Keyed by operation id.
     * @param list<Issue>              $issues
     * @param list<int>                $staleLinkIds Links pointing to entities that no longer exist.
     */
    public function __construct(
        public readonly array $operations,
        public readonly array $issues,
        public readonly BuildResult $build,
        public readonly array $staleLinkIds,
    ) {}

    /**
     * @return list<Operation>
     */
    public function ofType(string $type): array
    {
        $ops = array_values(array_filter(
            $this->operations,
            static fn (Operation $op): bool => $op->type === $type,
        ));
        usort($ops, static fn (Operation $a, Operation $b): int => strnatcasecmp($a->displayPath, $b->displayPath));

        return $ops;
    }

    public function get(string $id): ?Operation
    {
        return $this->operations[$id] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    /**
     * Expands a selection with the operations it requires, recursively.
     *
     * @param list<string> $selected
     *
     * @return array{selected: list<string>, added: list<string>, unknown: list<string>}
     */
    public function expandSelection(array $selected): array
    {
        $result = [];
        $added = [];
        $unknown = [];
        $stack = [];
        foreach ($selected as $id) {
            if (!isset($this->operations[$id])) {
                $unknown[] = $id;
                continue;
            }
            $stack[] = $id;
        }
        $explicit = array_flip($stack);

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = true;
            if (!isset($explicit[$id])) {
                $added[] = $id;
            }
            foreach ($this->operations[$id]->requires as $required) {
                if (isset($this->operations[$required]) && !isset($result[$required])) {
                    $stack[] = $required;
                }
            }
        }

        return ['selected' => array_keys($result), 'added' => $added, 'unknown' => $unknown];
    }

    /**
     * Orders operations for application: deletions deepest first, then by ascending
     * level (parents before children) updates, links and additions, so that a rename
     * or a deletion frees a name before another entity takes it.
     *
     * @param list<string> $ids
     *
     * @return list<Operation>
     */
    public function executionOrder(array $ids): array
    {
        $ops = [];
        foreach ($ids as $id) {
            if (isset($this->operations[$id])) {
                $ops[] = $this->operations[$id];
            }
        }

        $rank = [Operation::TYPE_UPDATE => 0, Operation::TYPE_LINK => 1, Operation::TYPE_ADD => 2];
        usort($ops, static function (Operation $a, Operation $b) use ($rank): int {
            $aDelete = $a->type === Operation::TYPE_DELETE;
            $bDelete = $b->type === Operation::TYPE_DELETE;
            if ($aDelete !== $bDelete) {
                return $aDelete ? -1 : 1;
            }
            if ($aDelete) {
                return [$b->level, $a->displayPath] <=> [$a->level, $b->displayPath];
            }

            return [$a->level, $rank[$a->type], $a->displayPath] <=> [$b->level, $rank[$b->type], $b->displayPath];
        });

        return $ops;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [
            Operation::TYPE_ADD    => 0,
            Operation::TYPE_LINK   => 0,
            Operation::TYPE_UPDATE => 0,
            Operation::TYPE_DELETE => 0,
        ];
        foreach ($this->operations as $op) {
            $counts[$op->type]++;
        }

        return $counts;
    }
}
