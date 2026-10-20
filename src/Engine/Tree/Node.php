<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Tree;

/**
 * Desired entity computed from the CSV.
 */
final class Node
{
    /**
     * @param list<string> $segments  Key of each level from level 1 to this node.
     * @param list<string> $namePath  Names of each level from level 1 to this node.
     */
    public function __construct(
        public readonly string $pathId,
        public readonly array $segments,
        public readonly int $level,
        public readonly string $name,
        public readonly ?string $comment,
        public readonly ?string $parentPathId,
        public readonly bool $isPlaceholder,
        public readonly int $firstLine,
        public readonly array $namePath,
    ) {}

    public function displayPath(): string
    {
        return implode(' > ', $this->namePath);
    }
}
