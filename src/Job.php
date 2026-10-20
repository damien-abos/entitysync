<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use CommonDBTM;
use CronTask;
use Session;

/**
 * Import job: an uploaded file, its preview and the user's selection, then its
 * application by the background task.
 *
 * Lifecycle: draft → queued → running → done, or cancelled / failed.
 * A running job asked to stop goes through "cancelling" until the end of its batch.
 */
class Job extends CommonDBTM
{
    public static $rightname = 'plugin_entitysync_model';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_CANCELLING = 'cancelling';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    /** Statuses of a job waiting for or being processed by the background task. */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_CANCELLING];

    /** Name of the automatic action (GLPI calls Job::cronEntitysyncApply()). */
    public const CRON_TASK = 'entitysyncApply';

    /** Default number of operations per batch (parameter of the automatic action). */
    public const DEFAULT_BATCH_SIZE = 100;

    /**
     * Maximum duration of one execution of the automatic action, in seconds. Can be
     * lowered with `define('PLUGIN_ENTITYSYNC_RUN_TIME_BUDGET', n)` in GLPI's
     * config/local_define.php (used by the tests to exercise the resumption).
     */
    public const RUN_TIME_BUDGET = 50;

    /** Drafts older than this are removed (seconds). */
    public const DRAFT_TTL = 86400;

    /** The background task is reported as not running after this delay (seconds). */
    public const CRON_LATE_AFTER = 180;

    public static function getTypeName($nb = 0)
    {
        return _n('Import job', 'Import jobs', $nb, 'entitysync');
    }

    public static function getIcon()
    {
        return 'ti ti-player-play';
    }

    public static function getUrl(int $jobId): string
    {
        return ImportController::getUrl() . '?job=' . $jobId;
    }

    public function isActive(): bool
    {
        return in_array($this->fields['status'] ?? '', self::ACTIVE_STATUSES, true);
    }

    public function isOwnedByCurrentUser(): bool
    {
        return (int) ($this->fields['users_id'] ?? 0) === (int) Session::getLoginUserID();
    }

    /**
     * @return list<string>
     */
    public function getSelection(): array
    {
        $ids = json_decode((string) ($this->fields['selection'] ?? '[]'), true);

        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
    }

    /**
     * @param list<string> $ids
     */
    public function saveSelection(array $ids): void
    {
        $this->updateFields(['selection' => json_encode(array_values($ids))]);
    }

    /**
     * @return array<string, string>
     */
    public function getForbiddenTypes(): array
    {
        $types = json_decode((string) ($this->fields['forbidden'] ?? '{}'), true);

        return is_array($types) ? array_map('strval', $types) : [];
    }

    /**
     * @return array<string, array{type: string, path: string, status: string, message: string}>
     */
    public function getResults(): array
    {
        $results = json_decode((string) ($this->fields['results'] ?? '{}'), true);

        return is_array($results) ? $results : [];
    }

    /**
     * Writes fields directly (no history, no hooks): jobs are technical records.
     *
     * @param array<string, mixed> $fields
     */
    public function updateFields(array $fields): void
    {
        global $DB;

        $fields['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->update(self::getTable(), $fields, ['id' => $this->getID()]);
        $this->fields = $fields + $this->fields;
    }

    /**
     * Re-reads the status, which another request may have changed (cancellation).
     */
    public function refreshStatus(): string
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['status'], 'FROM' => self::getTable(), 'WHERE' => ['id' => $this->getID()]])->current();
        $this->fields['status'] = (string) ($row['status'] ?? self::STATUS_FAILED);

        return $this->fields['status'];
    }

    /**
     * Active job of a model, if any.
     */
    public static function findActiveForModel(int $modelId, int $exceptJobId = 0): ?self
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_entitysync_models_id' => $modelId,
                'status'                      => self::ACTIVE_STATUSES,
                'NOT'                         => ['id' => $exceptJobId],
            ],
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return null;
        }
        $job = new self();

        return $job->getFromDB((int) $row['id']) ? $job : null;
    }

    /**
     * Jobs to show on the Import tab of a model: active ones, and drafts of the current user.
     *
     * @return list<array<string, mixed>>
     */
    public static function listOpenForModel(int $modelId): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['id', 'status', 'filename', 'users_id', 'nb_total', 'nb_processed', 'date_creation'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_entitysync_models_id' => $modelId,
                'OR'                          => [
                    ['status' => self::ACTIVE_STATUSES],
                    ['status' => self::STATUS_DRAFT, 'users_id' => Session::getLoginUserID()],
                ],
            ],
            'ORDER'  => ['id DESC'],
        ]);

        $jobs = [];
        foreach ($iterator as $row) {
            $jobs[] = $row + ['url' => self::getUrl((int) $row['id']), 'user_name' => getUserName((int) $row['users_id'])];
        }

        return $jobs;
    }

    /**
     * State of the automatic action, to warn when queued jobs are not processed.
     *
     * @return array{id: int, enabled: bool, external: bool, lastrun: string|null, late: bool, url: string}
     */
    public function cronHealth(): array
    {
        $task = new CronTask();
        $found = $task->getFromDBbyName(self::class, self::CRON_TASK);
        $lastrun = $found ? ($task->fields['lastrun'] ?? null) : null;
        $reference = $this->fields['date_queued'] ?? null;

        $late = false;
        if ($this->isActive() && $reference !== null) {
            $waiting = time() - strtotime((string) $reference);
            $ranSince = $lastrun !== null && strtotime((string) $lastrun) >= strtotime((string) $reference);
            $late = $waiting > self::CRON_LATE_AFTER && !$ranSince && ($this->fields['status'] ?? '') === self::STATUS_QUEUED;
        }

        return [
            'id'       => $found ? (int) $task->getID() : 0,
            'enabled'  => $found && (int) $task->fields['state'] !== CronTask::STATE_DISABLE,
            'external' => $found && (int) $task->fields['mode'] === CronTask::MODE_EXTERNAL,
            'lastrun'  => $lastrun,
            'late'     => $late || !$found || (int) $task->fields['state'] === CronTask::STATE_DISABLE,
            'url'      => $found ? CronTask::getFormURLWithID($task->getID()) : '',
        ];
    }

    public function cleanDBonPurge()
    {
        JobStorage::remove($this->getID());
    }

    /**
     * Description of the automatic action for GLPI.
     *
     * @return array{description: string, parameter?: string}
     */
    public static function cronInfo($name)
    {
        if ($name === self::CRON_TASK) {
            return [
                'description' => __('Apply the queued entity imports by batches', 'entitysync'),
                'parameter'   => __('Number of operations per batch', 'entitysync'),
            ];
        }

        return [];
    }

    /**
     * Automatic action: processes the queued jobs by batches, for at most
     * RUN_TIME_BUDGET seconds, and removes abandoned drafts.
     *
     * @return int 0 when there was nothing to do, 1 when everything was processed,
     *             -1 when work remains for the next execution.
     */
    public static function cronEntitysyncApply(CronTask $task): int
    {
        self::purgeExpiredDrafts();

        $batchSize = max(1, (int) ($task->fields['param'] ?? self::DEFAULT_BATCH_SIZE));
        $deadline = time() + self::runTimeBudget();
        $worked = false;

        while (time() < $deadline) {
            $job = self::nextToProcess();
            if ($job === null) {
                return $worked ? 1 : 0;
            }
            $worked = true;
            $processed = (new JobRunner($job))->run($batchSize, $deadline);
            $task->addVolume($processed);
        }

        return self::nextToProcess() !== null ? -1 : 1;
    }

    public static function runTimeBudget(): int
    {
        $budget = defined('PLUGIN_ENTITYSYNC_RUN_TIME_BUDGET') ? (int) constant('PLUGIN_ENTITYSYNC_RUN_TIME_BUDGET') : 0;

        return $budget > 0 ? min($budget, self::RUN_TIME_BUDGET) : self::RUN_TIME_BUDGET;
    }

    private static function nextToProcess(): ?self
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['status' => self::ACTIVE_STATUSES],
            'ORDER'  => ['date_queued ASC', 'id ASC'],
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return null;
        }
        $job = new self();

        return $job->getFromDB((int) $row['id']) ? $job : null;
    }

    private static function purgeExpiredDrafts(): void
    {
        global $DB;

        $limit = date('Y-m-d H:i:s', time() - self::DRAFT_TTL);
        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['status' => self::STATUS_DRAFT, 'date_creation' => ['<', $limit]],
        ]);
        $job = new self();
        foreach ($iterator as $row) {
            $job->delete(['id' => $row['id']], true);
        }

        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => ['status' => [self::STATUS_DRAFT, ...self::ACTIVE_STATUSES]]]) as $row) {
            $ids[] = (int) $row['id'];
        }
        JobStorage::removeOrphans($ids);
    }
}
