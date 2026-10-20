<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Sync;

use GlpiPlugin\Entitysync\Engine\Util\Msg;

/**
 * Applies the selected operations of a plan, in dependency order.
 *
 * Each operation is applied independently: a failure is recorded and the operations
 * that depend on it are skipped, the others go on.
 */
final class Applier
{
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** @var array<string, int> Path id => id of the entity created by this instance. */
    private array $created = [];

    /** @var array<string, string> Operation id => status, for the operations applied by this instance. */
    private array $status = [];

    private bool $staleLinksCleaned = false;

    public function __construct(private readonly GatewayInterface $gateway) {}

    /**
     * Applies a selection in one go: the selection is first expanded with the
     * operations it requires.
     *
     * @param list<string> $selectedIds Operation ids chosen by the user.
     *
     * @return array{
     *     results: list<array{id: string, type: string, path: string, status: string, message: string}>,
     *     counts: array<string, int>,
     *     auto_selected: list<string>,
     *     unknown: list<string>
     * }
     */
    public function apply(Plan $plan, array $selectedIds): array
    {
        $selection = $plan->expandSelection($selectedIds);
        $report = $this->applyBatch($plan, $selection['selected']);

        return $report + [
            'auto_selected' => $selection['added'],
            'unknown'       => $selection['unknown'],
        ];
    }

    /**
     * Applies exactly the given operations (no expansion), in execution order.
     *
     * Can be called several times on the same instance and plan (successive batches):
     * entities created by earlier calls are known to later ones. A prerequisite must
     * have been applied by this instance; when the prerequisite was applied by an
     * earlier run, the recomputed plan no longer contains it nor the requirement.
     * A prerequisite that failed earlier and is not re-submitted makes its dependents
     * skipped.
     *
     * @param list<string> $ids
     *
     * @return array{
     *     results: list<array{id: string, type: string, path: string, status: string, message: string}>,
     *     counts: array<string, int>
     * }
     */
    public function applyBatch(Plan $plan, array $ids): array
    {
        if ($plan->staleLinkIds !== [] && !$this->staleLinksCleaned) {
            $this->staleLinksCleaned = true;
            try {
                $this->gateway->removeLinksById($plan->staleLinkIds);
            } catch (\RuntimeException) {
                // Non-blocking: stale links are cleaned up again on the next run.
            }
        }

        $results = [];
        $counts = self::emptyCounts();

        foreach ($plan->executionOrder($ids) as $op) {
            $id = $op->id();

            $skipReason = $this->skipReason($op, $plan);
            if ($skipReason !== null) {
                $this->status[$id] = self::STATUS_SKIPPED;
                $counts[self::STATUS_SKIPPED]++;
                $results[] = $this->result($op, self::STATUS_SKIPPED, $skipReason);
                continue;
            }

            try {
                $message = $this->applyOne($op, $this->created);
                $this->status[$id] = self::STATUS_DONE;
                $counts[$op->type]++;
                $results[] = $this->result($op, self::STATUS_DONE, $message);
            } catch (\RuntimeException $e) {
                $this->status[$id] = self::STATUS_FAILED;
                $counts[self::STATUS_FAILED]++;
                $results[] = $this->result($op, self::STATUS_FAILED, $e->getMessage());
            }
        }

        return ['results' => $results, 'counts' => $counts];
    }

    /**
     * @return array<string, int>
     */
    public static function emptyCounts(): array
    {
        return [
            Operation::TYPE_ADD    => 0,
            Operation::TYPE_LINK   => 0,
            Operation::TYPE_UPDATE => 0,
            Operation::TYPE_DELETE => 0,
            self::STATUS_FAILED    => 0,
            self::STATUS_SKIPPED   => 0,
        ];
    }

    private function skipReason(Operation $op, Plan $plan): ?string
    {
        if ($op->isBlocked()) {
            return implode(' ', $op->blockReasons);
        }

        foreach ($op->requires as $required) {
            // Requirements always point to operations of the same plan: a prerequisite
            // applied by an earlier run is no longer in the plan, so it is not listed.
            if (($this->status[$required] ?? null) !== self::STATUS_DONE) {
                $requiredOp = $plan->get($required);

                return Msg::t(
                    'Required operation not applied (%s).',
                    $requiredOp !== null ? $requiredOp->displayPath : $required,
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $created
     *
     * @throws \RuntimeException
     */
    private function applyOne(Operation $op, array &$created): string
    {
        switch ($op->type) {
            case Operation::TYPE_ADD:
                $parentId = $this->parentId($op, $created);
                $entityId = $this->gateway->createEntity($op->name, $op->comment, $parentId);
                $created[$op->pathId] = $entityId;
                $this->gateway->saveLink($op->pathId, $entityId);

                return Msg::t('Entity created (ID %d).', $entityId);

            case Operation::TYPE_LINK:
                $entityId = (int) $op->entityId;
                $fields = $this->fieldsFromChanges($op, $created);
                if ($fields !== []) {
                    $this->gateway->updateEntity($entityId, $fields);
                }
                if ($op->previousPathId !== null) {
                    $this->gateway->removeLink($op->previousPathId);
                }
                $this->gateway->saveLink($op->pathId, $entityId);

                return $fields === []
                    ? Msg::t('Entity linked (ID %d).', $entityId)
                    : Msg::t('Entity linked and updated (ID %d).', $entityId);

            case Operation::TYPE_UPDATE:
                $entityId = (int) $op->entityId;
                $this->gateway->updateEntity($entityId, $this->fieldsFromChanges($op, $created));

                return Msg::t('Entity updated (ID %d).', $entityId);

            case Operation::TYPE_DELETE:
                $entityId = (int) $op->entityId;
                $this->gateway->deleteEntity($entityId);
                $this->gateway->removeLink($op->pathId);

                return Msg::t('Entity deleted (ID %d).', $entityId);
        }

        throw new \RuntimeException(Msg::t('Unknown operation type "%s".', $op->type));
    }

    /**
     * @param array<string, int> $created
     *
     * @return array{name?: string, comment?: string, entities_id?: int}
     */
    private function fieldsFromChanges(Operation $op, array $created): array
    {
        $fields = [];
        foreach ($op->changes as $change) {
            switch ($change['field']) {
                case Operation::FIELD_NAME:
                    $fields['name'] = $op->name;
                    break;
                case Operation::FIELD_COMMENT:
                    $fields['comment'] = (string) $op->comment;
                    break;
                case Operation::FIELD_PARENT:
                    $fields['entities_id'] = $this->parentId($op, $created);
                    break;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, int> $created
     */
    private function parentId(Operation $op, array $created): int
    {
        if ($op->parentEntityId !== null) {
            return $op->parentEntityId;
        }
        if ($op->parentPathId !== null && isset($created[$op->parentPathId])) {
            return $created[$op->parentPathId];
        }

        throw new \RuntimeException(Msg::t('The parent entity has not been created.'));
    }

    /**
     * @return array{id: string, type: string, path: string, status: string, message: string}
     */
    private function result(Operation $op, string $status, string $message): array
    {
        return [
            'id'      => $op->id(),
            'type'    => $op->type,
            'path'    => $op->displayPath,
            'status'  => $status,
            'message' => $message,
        ];
    }
}
