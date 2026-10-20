<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Tree;

/**
 * Result of the CSV to tree transformation.
 */
final class BuildResult
{
    /**
     * @param array<string, Node>  $nodes          Desired nodes keyed by path id, parents first.
     * @param array<string, true>  $protectedPaths Path ids that must not be proposed for deletion
     *                                             (rows in error, name collisions).
     * @param list<Issue>          $issues
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $protectedPaths,
        public readonly array $issues,
        public readonly int $totalRows,
        public readonly int $filteredOutRows,
        public readonly int $invalidRows,
    ) {}

    public function keptRows(): int
    {
        return $this->totalRows - $this->filteredOutRows;
    }
}
