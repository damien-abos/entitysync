<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Sync;

/**
 * Server-side selection of the operations of a plan, for the paginated preview.
 *
 * Works on the array form of the operations ({@see Operation::toArray()}), as stored
 * in the preview snapshot, and keeps the selection consistent with dependencies:
 * selecting an operation selects the operations it requires; unselecting one
 * unselects the operations that require it. Blocked operations are never selected.
 */
final class Selection
{
    public const TYPES = [Operation::TYPE_ADD, Operation::TYPE_LINK, Operation::TYPE_UPDATE, Operation::TYPE_DELETE];

    /** @var array<string, array<string, mixed>> */
    private array $ops;

    /** @var array<string, true> */
    private array $selected = [];

    /** @var array<string, list<string>> Operation id => ids of the operations requiring it. */
    private array $dependents = [];

    /** @var array<string, list<string>> Type => operation ids sorted by path. */
    private array $order = [];

    /** @var array<string, string> Operation id => lower-cased path, for searches. */
    private array $searchPaths = [];

    /**
     * @param array<string, array<string, mixed>> $ops         Operations keyed by id.
     * @param list<string>                        $selectedIds Initial selection (unknown and blocked ids are ignored).
     */
    public function __construct(array $ops, array $selectedIds)
    {
        $this->ops = $ops;
        foreach (self::TYPES as $type) {
            $this->order[$type] = [];
        }
        foreach ($ops as $id => $op) {
            $this->order[(string) $op['type']][] = (string) $id;
            $this->searchPaths[(string) $id] = mb_strtolower((string) $op['path'], 'UTF-8');
            foreach ((array) ($op['requires'] ?? []) as $required) {
                $this->dependents[(string) $required][] = (string) $id;
            }
        }
        foreach ($this->order as &$ids) {
            usort($ids, fn (string $a, string $b): int => strnatcasecmp((string) $this->ops[$a]['path'], (string) $this->ops[$b]['path']));
        }
        unset($ids);

        foreach ($selectedIds as $id) {
            if ($this->isSelectable($id)) {
                $this->selected[$id] = true;
            }
        }
    }

    /**
     * Default selection of a new preview: every operation that is not blocked,
     * except deletions, together with what they require.
     *
     * @param array<string, array<string, mixed>> $ops
     *
     * @return list<string>
     */
    public static function defaultIds(array $ops): array
    {
        $selection = new self($ops, []);
        $ids = [];
        foreach ($ops as $id => $op) {
            if ($op['type'] !== Operation::TYPE_DELETE) {
                $ids[] = (string) $id;
            }
        }
        $selection->select($ids);

        return $selection->ids();
    }

    public function isSelectable(string $id): bool
    {
        return isset($this->ops[$id]) && !($this->ops[$id]['blocked'] ?? false);
    }

    public function isSelected(string $id): bool
    {
        return isset($this->selected[$id]);
    }

    /**
     * @param list<string> $ids
     */
    public function select(array $ids): void
    {
        $stack = $ids;
        while ($stack !== []) {
            $id = (string) array_pop($stack);
            if (isset($this->selected[$id]) || !$this->isSelectable($id)) {
                continue;
            }
            $this->selected[$id] = true;
            foreach ((array) ($this->ops[$id]['requires'] ?? []) as $required) {
                $stack[] = (string) $required;
            }
        }
    }

    /**
     * @param list<string> $ids
     */
    public function deselect(array $ids): void
    {
        $stack = $ids;
        while ($stack !== []) {
            $id = (string) array_pop($stack);
            if (!isset($this->selected[$id])) {
                continue;
            }
            unset($this->selected[$id]);
            foreach ($this->dependents[$id] ?? [] as $dependent) {
                $stack[] = $dependent;
            }
        }
    }

    /**
     * Operation ids whose path contains the query (case insensitive), in display order.
     *
     * @param string|null $type Restrict to one type; null for every type.
     *
     * @return list<string>
     */
    public function matching(?string $type, string $query = ''): array
    {
        $types = $type === null ? self::TYPES : [$type];
        $query = mb_strtolower(trim($query), 'UTF-8');
        $ids = [];
        foreach ($types as $t) {
            foreach ($this->order[$t] ?? [] as $id) {
                if ($query === '' || str_contains($this->searchPaths[$id], $query)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    public function selectMatching(?string $type, string $query = ''): void
    {
        $this->select($this->matching($type, $query));
    }

    public function deselectMatching(?string $type, string $query = ''): void
    {
        $this->deselect($this->matching($type, $query));
    }

    /**
     * One page of a section.
     *
     * @return array{ids: list<string>, total: int, page: int, pages: int, size: int}
     */
    public function page(string $type, string $query, int $page, int $size): array
    {
        $ids = $this->matching($type, $query);
        $size = max(1, $size);
        $pages = max(1, (int) ceil(count($ids) / $size));
        $page = min(max(1, $page), $pages);

        return [
            'ids'   => array_slice($ids, ($page - 1) * $size, $size),
            'total' => count($ids),
            'page'  => $page,
            'pages' => $pages,
            'size'  => $size,
        ];
    }

    /**
     * Counters for the toolbar and the section headers.
     *
     * @return array{
     *     selected: int,
     *     deletions: int,
     *     sections: array<string, array{total: int, matching: int, selectable: int, selected: int}>
     * }
     */
    public function summary(string $query = ''): array
    {
        $sections = [];
        foreach (self::TYPES as $type) {
            $matching = $this->matching($type, $query);
            $selectable = 0;
            $selected = 0;
            foreach ($matching as $id) {
                if ($this->isSelectable($id)) {
                    $selectable++;
                }
                if (isset($this->selected[$id])) {
                    $selected++;
                }
            }
            $sections[$type] = [
                'total'      => count($this->order[$type]),
                'matching'   => count($matching),
                'selectable' => $selectable,
                'selected'   => $selected,
            ];
        }

        $deletions = 0;
        foreach ($this->selected as $id => $_) {
            if ($this->ops[$id]['type'] === Operation::TYPE_DELETE) {
                $deletions++;
            }
        }

        return ['selected' => count($this->selected), 'deletions' => $deletions, 'sections' => $sections];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->selected);
    }
}
