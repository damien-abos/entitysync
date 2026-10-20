<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Engine\Sync\Applier;

/**
 * Applies a queued job by batches, from the background task.
 *
 * At each execution, the plan is recomputed from the file and the current GLPI
 * state, then the selected operations not processed yet are applied in execution
 * order, batch after batch, until the time budget is spent. Progress is saved after
 * each batch, so an interrupted execution resumes where it stopped.
 */
final class JobRunner
{
    public function __construct(private readonly Job $job) {}

    /**
     * @return int Number of operations processed by this call.
     */
    public function run(int $batchSize, int $deadline): int
    {
        $job = $this->job;
        $now = static fn (): string => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $status = $job->refreshStatus();
        if ($status === Job::STATUS_CANCELLING) {
            $this->finish(Job::STATUS_CANCELLED);
            return 0;
        }
        if ($status === Job::STATUS_QUEUED) {
            $job->updateFields(['status' => Job::STATUS_RUNNING, 'date_start' => $now()]);
        }

        $model = new Model();
        if (!$model->getFromDB((int) $job->fields['plugin_entitysync_models_id'])) {
            $this->fail(__('The import model no longer exists.', 'entitysync'));
            return 0;
        }

        // Large jobs: the PHP time limit must not stop a batch half-way.
        @set_time_limit(0);

        try {
            $csv = $model->csvReader()->readFile(JobStorage::csvPath($job->getID()));
            $plan = PlanFactory::compute($model, $csv, $job->getForbiddenTypes());
        } catch (EngineException $e) {
            $this->fail($e->getMessage());
            return 0;
        }

        $selected = $job->getSelection();
        $results = $job->getResults();
        $counters = $this->counters();
        $applier = new Applier(new GlpiGateway($model->getID()));
        $processed = 0;

        while (true) {
            $remaining = array_values(array_filter(
                $selected,
                static fn (string $id): bool => !isset($results[$id]) && $plan->get($id) !== null,
            ));
            if ($remaining === []) {
                $this->finish(Job::STATUS_DONE, $results, $counters);
                return $processed;
            }

            $batch = array_map(
                static fn ($op): string => $op->id(),
                array_slice($plan->executionOrder($remaining), 0, $batchSize),
            );
            $report = $applier->applyBatch($plan, $batch);

            foreach ($report['results'] as $result) {
                $results[$result['id']] = [
                    'type'    => $result['type'],
                    'path'    => $result['path'],
                    'status'  => $result['status'],
                    'message' => $result['message'],
                ];
            }
            foreach ($report['counts'] as $key => $count) {
                $counters[$key] += $count;
            }
            $processed += count($report['results']);

            $job->updateFields([
                'results'      => json_encode($results, JSON_UNESCAPED_UNICODE),
                'nb_processed' => count($results),
                'nb_added'     => $counters['add'],
                'nb_linked'    => $counters['link'],
                'nb_updated'   => $counters['update'],
                'nb_deleted'   => $counters['delete'],
                'nb_failed'    => $counters['failed'],
                'nb_skipped'   => $counters['skipped'],
            ]);

            if ($job->refreshStatus() === Job::STATUS_CANCELLING) {
                $this->finish(Job::STATUS_CANCELLED, $results, $counters);
                return $processed;
            }
            if (time() >= $deadline) {
                return $processed;
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function counters(): array
    {
        $fields = $this->job->fields;

        return [
            'add'     => (int) $fields['nb_added'],
            'link'    => (int) $fields['nb_linked'],
            'update'  => (int) $fields['nb_updated'],
            'delete'  => (int) $fields['nb_deleted'],
            'failed'  => (int) $fields['nb_failed'],
            'skipped' => (int) $fields['nb_skipped'],
        ];
    }

    /**
     * Ends the job: records the import run (when something was processed), removes
     * the files and the bulky data.
     *
     * @param array<string, array{type: string, path: string, status: string, message: string}> $results
     * @param array<string, int>|null $counters
     */
    private function finish(string $status, ?array $results = null, ?array $counters = null): void
    {
        $job = $this->job;
        $results ??= $job->getResults();
        $counters ??= $this->counters();

        if ($status === Job::STATUS_DONE) {
            // Selected operations that are no longer part of the plan: the data changed
            // since the preview (applied by someone else, entity moved, row removed...).
            foreach ($job->getSelection() as $id) {
                if (!isset($results[$id])) {
                    $results[$id] = [
                        'type'    => '',
                        'path'    => '',
                        'status'  => Applier::STATUS_SKIPPED,
                        'message' => __('No longer applies: the data changed since the preview.', 'entitysync'),
                    ];
                    $counters['skipped']++;
                }
            }
        }

        $runId = 0;
        if ($results !== []) {
            $model = new Model();
            if ($model->getFromDB((int) $job->fields['plugin_entitysync_models_id'])) {
                $runId = Run::record(
                    $model,
                    (string) $job->fields['filename'],
                    (int) $job->fields['nb_rows'],
                    (int) $job->fields['nb_total'],
                    ['results' => array_map(
                        static fn (string $id, array $r): array => ['id' => $id] + $r,
                        array_keys($results),
                        array_values($results),
                    ), 'counts' => $counters],
                    (int) $job->fields['users_id'],
                    $status,
                );
            }
        }

        $job->updateFields([
            'status'                    => $status,
            'date_end'                  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'nb_processed'              => count($results),
            'nb_skipped'                => $counters['skipped'],
            'plugin_entitysync_runs_id' => $runId,
            'results'                   => null,
            'selection'                 => null,
        ]);
        JobStorage::remove($job->getID());
    }

    private function fail(string $message): void
    {
        $this->job->updateFields(['message' => $message]);
        $this->finish(Job::STATUS_FAILED);
    }
}
