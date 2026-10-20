<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use Entity;
use GlpiPlugin\Entitysync\Engine\Sync\ExistingState;
use GlpiPlugin\Entitysync\Engine\Sync\GatewayInterface;
use GlpiPlugin\Entitysync\Engine\Tree\Path;
use Session;

/**
 * GLPI implementation of the engine gateway: reads the current state and writes
 * entities through the `Entity` class (so GLPI hooks, history and caches apply).
 */
final class GlpiGateway implements GatewayInterface
{
    /** Tables holding an `entities_id` column that are not "items" of the entity. */
    private const USAGE_EXCLUDED_TABLES = ['glpi_entities'];

    public function __construct(private readonly int $modelId) {}

    public function loadState(int $rootEntityId): ExistingState
    {
        global $DB;

        $links = [];
        $linkedIds = [];
        $foreign = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'plugin_entitysync_models_id', 'entities_id', 'path'],
            'FROM'   => Link::getTable(),
        ]);
        foreach ($iterator as $row) {
            if ((int) $row['plugin_entitysync_models_id'] === $this->modelId) {
                $links[(string) $row['path']] = ['id' => (int) $row['id'], 'entity_id' => (int) $row['entities_id']];
                $linkedIds[] = (int) $row['entities_id'];
            } else {
                $foreign[(int) $row['entities_id']] = true;
            }
        }

        // Root, its descendants, and managed entities that may have been moved elsewhere.
        $ids = array_map('intval', array_values(getSonsOf(Entity::getTable(), $rootEntityId)));
        $ids = array_values(array_unique(array_merge([$rootEntityId], $ids, $linkedIds)));

        $entities = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $iterator = $DB->request([
                'SELECT' => ['id', 'name', 'comment', 'entities_id', 'completename'],
                'FROM'   => Entity::getTable(),
                'WHERE'  => ['id' => $chunk],
            ]);
            foreach ($iterator as $row) {
                $entities[(int) $row['id']] = [
                    'id'           => (int) $row['id'],
                    'name'         => (string) $row['name'],
                    'comment'      => $row['comment'] !== null ? (string) $row['comment'] : null,
                    'parent_id'    => $row['entities_id'] !== null && (int) $row['id'] !== 0 ? (int) $row['entities_id'] : null,
                    'completename' => (string) $row['completename'],
                ];
            }
        }

        return new ExistingState($entities, $links, $foreign);
    }

    /**
     * Lists, for each entity, the items still attached to it (any table with an
     * `entities_id` column), so that the deletion can be blocked.
     *
     * @param list<int> $entityIds
     *
     * @return array<int, list<string>>
     */
    public function inspectUsage(array $entityIds): array
    {
        global $DB;

        if ($entityIds === []) {
            return [];
        }

        $tables = $DB->request([
            'SELECT' => ['TABLE_NAME AS table_name'],
            'FROM'   => 'information_schema.columns',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'COLUMN_NAME'  => 'entities_id',
            ],
        ]);

        $counts = [];
        foreach ($tables as $row) {
            $table = (string) $row['table_name'];
            if (
                in_array($table, self::USAGE_EXCLUDED_TABLES, true)
                || str_starts_with($table, 'glpi_plugin_entitysync_')
                || !str_starts_with($table, 'glpi_')
            ) {
                continue;
            }

            $iterator = $DB->request([
                'SELECT'  => ['entities_id', 'COUNT' => '* AS cpt'],
                'FROM'    => $table,
                'WHERE'   => ['entities_id' => $entityIds],
                'GROUPBY' => 'entities_id',
            ]);
            foreach ($iterator as $count) {
                $counts[(int) $count['entities_id']][$table] = (int) $count['cpt'];
            }
        }

        $reasons = [];
        foreach ($counts as $entityId => $byTable) {
            $parts = [];
            foreach ($byTable as $table => $count) {
                $parts[] = sprintf('%s (%d)', $this->tableLabel($table, $count), $count);
            }
            $reasons[$entityId] = [sprintf(__('Still contains: %s.', 'entitysync'), implode(', ', $parts))];
        }

        return $reasons;
    }

    public function createEntity(string $name, ?string $comment, int $parentId): int
    {
        $entity = new Entity();
        $input = [
            'name'        => $name,
            'entities_id' => $parentId,
            '_no_message' => true,
        ];
        if ($comment !== null) {
            $input['comment'] = $comment;
        }

        $id = $this->guard(static fn () => $entity->add($input));
        if (!$id) {
            throw new \RuntimeException($this->lastError ?: __('The entity could not be created.', 'entitysync'));
        }

        return (int) $id;
    }

    public function updateEntity(int $entityId, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $entity = new Entity();
        if (!$entity->getFromDB($entityId)) {
            throw new \RuntimeException(__('The entity no longer exists.', 'entitysync'));
        }

        $ok = $this->guard(static fn () => $entity->update(['id' => $entityId, '_no_message' => true] + $fields));
        if (!$ok) {
            throw new \RuntimeException($this->lastError ?: __('The entity could not be updated.', 'entitysync'));
        }
    }

    public function deleteEntity(int $entityId): void
    {
        $entity = new Entity();
        if (!$entity->getFromDB($entityId)) {
            return; // Already gone.
        }

        $ok = $this->guard(static fn () => $entity->delete(['id' => $entityId, '_no_message' => true], true));
        if (!$ok) {
            throw new \RuntimeException($this->lastError ?: __('The entity could not be deleted.', 'entitysync'));
        }
    }

    public function saveLink(string $pathId, int $entityId): void
    {
        global $DB;

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $hash = Path::hash($pathId);
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Link::getTable(),
            'WHERE'  => ['plugin_entitysync_models_id' => $this->modelId, 'path_hash' => $hash],
        ])->current();

        if ($existing !== null) {
            $DB->update(Link::getTable(), ['entities_id' => $entityId, 'path' => $pathId, 'date_mod' => $now], ['id' => $existing['id']]);
            return;
        }

        $DB->insert(Link::getTable(), [
            'plugin_entitysync_models_id' => $this->modelId,
            'entities_id'                 => $entityId,
            'path_hash'                   => $hash,
            'path'                        => $pathId,
            'date_creation'               => $now,
            'date_mod'                    => $now,
        ]);
    }

    public function removeLink(string $pathId): void
    {
        global $DB;

        $DB->delete(Link::getTable(), [
            'plugin_entitysync_models_id' => $this->modelId,
            'path_hash'                   => Path::hash($pathId),
        ]);
    }

    public function removeLinksById(array $linkIds): void
    {
        global $DB;

        if ($linkIds !== []) {
            $DB->delete(Link::getTable(), ['id' => $linkIds, 'plugin_entitysync_models_id' => $this->modelId]);
        }
    }

    /** Error messages GLPI produced during the last guarded call. */
    private string $lastError = '';

    /**
     * Runs a GLPI write, captures the messages it queues for the user (they are
     * reported per operation instead) and converts exceptions.
     *
     * @param callable(): mixed $callback
     */
    private function guard(callable $callback): mixed
    {
        $before = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $this->lastError = '';

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $result = false;
            $this->lastError = $e->getMessage();
        }

        $after = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $new = [];
        foreach ($after as $type => $messages) {
            foreach ($messages as $message) {
                if (!in_array($message, $before[$type] ?? [], true)) {
                    $new[] = html_entity_decode(strip_tags((string) $message), ENT_QUOTES | ENT_HTML5);
                }
            }
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $before;

        if ($new !== []) {
            $this->lastError = trim($this->lastError . ' ' . implode(' ', $new));
        }

        return $result;
    }

    private function tableLabel(string $table, int $count): string
    {
        $itemtype = getItemTypeForTable($table);
        if (is_string($itemtype) && $itemtype !== '' && class_exists($itemtype) && method_exists($itemtype, 'getTypeName')) {
            return $itemtype::getTypeName($count);
        }

        return $table;
    }

    /**
     * Rights-based restrictions applied to the plan.
     *
     * @return array<string, string> Operation type => reason.
     */
    public static function forbiddenTypes(): array
    {
        $forbidden = [];
        if (!Session::haveRight(Entity::$rightname, CREATE) || !Session::haveRight(Entity::$rightname, UPDATE)) {
            $forbidden['add'] = __('You are not allowed to create entities.', 'entitysync');
        }
        if (!Session::haveRight(Entity::$rightname, UPDATE)) {
            $forbidden['update'] = __('You are not allowed to update entities.', 'entitysync');
            $forbidden['link'] = $forbidden['update'];
        }
        if (!Session::haveRight(Entity::$rightname, PURGE)) {
            $forbidden['delete'] = __('You are not allowed to delete entities.', 'entitysync');
        }

        return $forbidden;
    }
}
