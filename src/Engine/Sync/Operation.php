<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Sync;

use GlpiPlugin\Entitysync\Engine\Tree\Path;

/**
 * One proposed change to the entity tree.
 */
final class Operation
{
    public const TYPE_ADD = 'add';
    public const TYPE_LINK = 'link';
    public const TYPE_UPDATE = 'update';
    public const TYPE_DELETE = 'delete';

    public const FIELD_NAME = 'name';
    public const FIELD_COMMENT = 'comment';
    public const FIELD_PARENT = 'parent';

    /** @var list<array{field: string, old: string, new: string}> */
    public array $changes = [];

    /** @var list<string> Ids of the operations this one requires. */
    public array $requires = [];

    /** @var list<string> Reasons why the operation cannot be applied. */
    public array $blockReasons = [];

    /** Path id the entity was linked to before a key change (link operations only). */
    public ?string $previousPathId = null;

    public function __construct(
        public readonly string $type,
        public readonly string $pathId,
        public readonly int $level,
        public readonly string $displayPath,
        public readonly string $name,
        public readonly ?string $comment,
        public readonly ?int $entityId,
        public readonly ?string $parentPathId,
        public readonly ?int $parentEntityId,
        public readonly ?int $line,
    ) {}

    public static function makeId(string $type, string $pathId): string
    {
        return substr(sha1($type . "\0" . $pathId), 0, 16);
    }

    public function id(): string
    {
        return self::makeId($this->type, $this->pathId);
    }

    public function pathHash(): string
    {
        return Path::hash($this->pathId);
    }

    public function isBlocked(): bool
    {
        return $this->blockReasons !== [];
    }

    public function hasChange(string $field): bool
    {
        foreach ($this->changes as $change) {
            if ($change['field'] === $field) {
                return true;
            }
        }

        return false;
    }

    public function block(string $reason): void
    {
        if (!in_array($reason, $this->blockReasons, true)) {
            $this->blockReasons[] = $reason;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id(),
            'type'           => $this->type,
            'level'          => $this->level,
            'path'           => $this->displayPath,
            'name'           => $this->name,
            'comment'        => $this->comment,
            'entity_id'      => $this->entityId,
            'line'           => $this->line,
            'changes'        => $this->changes,
            'requires'       => $this->requires,
            'blocked'        => $this->isBlocked(),
            'block_reasons'  => $this->blockReasons,
            'previous_path'  => $this->previousPathId !== null ? Path::displayKeys(Path::segments($this->previousPathId)) : null,
        ];
    }
}
