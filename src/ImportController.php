<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Engine\Sync\Operation;
use GlpiPlugin\Entitysync\Engine\Sync\Selection;
use Html;
use Session;

/**
 * Import workflow, built around import jobs:
 *
 * 1. upload: the file is stored and a draft job is created with a snapshot of the
 *    preview (operations, issues, counters);
 * 2. preview: the operations are browsed page by page and selected through AJAX
 *    calls, the selection being kept in the job (never posted as one field per
 *    operation, which would hit PHP's max_input_vars limit);
 * 3. queue: the job is handed over to the background task, which recomputes the plan
 *    and applies the selection by batches ({@see JobRunner});
 * 4. status: progress page, refreshed while the job runs.
 */
final class ImportController
{
    /** Page sizes offered in the preview. */
    public const PAGE_SIZES = [50, 100, 500];

    /** Data issues displayed in the preview (the total is always shown). */
    public const ISSUES_LIMIT = 200;

    public static function getUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/entitysync/front/import.php';
    }

    public static function getAjaxUrl(string $script): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/entitysync/ajax/' . $script . '.php';
    }

    /**
     * Stores the uploaded file, computes the preview and returns the URL of the job.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function upload(array $post, array $files): string
    {
        $model = $this->loadModel((int) ($post['models_id'] ?? 0));

        $file = $files['csv_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            Session::addMessageAfterRedirect(__s('No file was received.', 'entitysync'), false, ERROR);
            return $model->getLinkURL();
        }

        $job = new Job();
        $jobId = (int) $job->add([
            'plugin_entitysync_models_id' => $model->getID(),
            'users_id'                    => Session::getLoginUserID(),
            'status'                      => Job::STATUS_DRAFT,
            'filename'                    => mb_substr(basename((string) ($file['name'] ?? 'import.csv')), 0, 255),
            '_no_message'                 => true,
        ]);
        if ($jobId <= 0 || !JobStorage::storeUpload((string) $file['tmp_name'], $jobId)) {
            if ($jobId > 0) {
                $job->delete(['id' => $jobId], true);
            }
            Session::addMessageAfterRedirect(__s('The file could not be stored.', 'entitysync'), false, ERROR);
            return $model->getLinkURL();
        }

        try {
            $csv = $model->csvReader()->readFile(JobStorage::csvPath($jobId));
            $model->rememberColumns($csv->columns);
            $plan = PlanFactory::compute($model, $csv, GlpiGateway::forbiddenTypes());
        } catch (EngineException $e) {
            $job->updateFields(['status' => Job::STATUS_FAILED, 'message' => $e->getMessage()]);
            JobStorage::remove($jobId);
            return Job::getUrl($jobId);
        }

        $operations = [];
        foreach ($plan->operations as $id => $op) {
            $operations[$id] = $op->toArray();
        }
        $issues = array_map(static fn ($issue): array => $issue->toArray(), $plan->issues);

        JobStorage::writeSnapshot($jobId, [
            'csv'          => [
                'encoding'  => $csv->detectedEncoding,
                'delimiter' => $csv->detectedDelimiter === "\t" ? 'TAB' : $csv->detectedDelimiter,
            ],
            'stats'        => [
                'rows'     => $plan->build->totalRows,
                'filtered' => $plan->build->filteredOutRows,
                'invalid'  => $plan->build->invalidRows,
                'nodes'    => count($plan->build->nodes),
            ],
            'counts'       => $plan->counts(),
            'issues'       => array_slice($issues, 0, self::ISSUES_LIMIT),
            'issues_total' => count($issues),
            'errors_total' => count(array_filter($issues, static fn (array $i): bool => $i['severity'] === 'error')),
            'operations'   => $operations,
        ]);

        $job->updateFields([
            'nb_rows'   => $plan->build->totalRows,
            'selection' => json_encode(Selection::defaultIds($operations)),
        ]);

        return Job::getUrl($jobId);
    }

    /**
     * Displays a job: the selection panel for a draft, the progress page otherwise.
     */
    public function show(int $jobId): void
    {
        [$job, $model] = $this->loadJob($jobId);

        if ($job->fields['status'] !== Job::STATUS_DRAFT) {
            $this->showStatus($job, $model);
            return;
        }

        $snapshot = JobStorage::readSnapshot($job->getID());
        if ($snapshot === null) {
            $job->delete(['id' => $job->getID()], true);
            Session::addMessageAfterRedirect(__s('This import has expired. Please upload the file again.', 'entitysync'), false, WARNING);
            Html::redirect($model->getLinkURL());
        }

        $selection = new Selection($snapshot['operations'], $job->getSelection());
        $active = Job::findActiveForModel($model->getID());

        TemplateRenderer::getInstance()->display('@entitysync/preview.html.twig', [
            'model'       => $model,
            'job'         => $job,
            'action'      => self::getUrl(),
            'snapshot'    => $snapshot,
            'summary'     => $selection->summary(),
            'owner'       => $job->isOwnedByCurrentUser(),
            'active_job'  => $active !== null ? ['id' => $active->getID(), 'url' => Job::getUrl($active->getID())] : null,
            'config'      => [
                'job'          => $job->getID(),
                'pageUrl'      => self::getAjaxUrl('preview'),
                'selectionUrl' => self::getAjaxUrl('selection'),
                'pageSizes'    => self::PAGE_SIZES,
                'readOnly'     => !$job->isOwnedByCurrentUser(),
                'labels'       => [
                    'page'     => __('Page %1$d of %2$d', 'entitysync'),
                    'items'    => __('%d item(s)', 'entitysync'),
                    'counter'  => __('%1$d selected change(s), including %2$d deletion(s)', 'entitysync'),
                    'confirm'  => __('%d entity(ies) will be permanently deleted. Continue?', 'entitysync'),
                    'empty'    => __('No matching change.', 'entitysync'),
                    'error'    => __('The server could not be reached. Reload the page.', 'entitysync'),
                ],
            ],
        ]);
    }

    /**
     * Rows of one section of the preview (AJAX).
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function page(array $query): array
    {
        [$job, , $snapshot] = $this->loadDraft((int) ($query['job'] ?? 0));
        $type = (string) ($query['type'] ?? '');
        if (!in_array($type, Selection::TYPES, true)) {
            throw new BadRequestHttpException();
        }
        $size = (int) ($query['size'] ?? 100);
        if (!in_array($size, self::PAGE_SIZES, true)) {
            $size = 100;
        }

        $selection = new Selection($snapshot['operations'], $job->getSelection());
        $page = $selection->page($type, (string) ($query['q'] ?? ''), (int) ($query['page'] ?? 1), $size);

        $rows = [];
        foreach ($page['ids'] as $id) {
            $rows[] = $snapshot['operations'][$id] + ['selected' => $selection->isSelected($id)];
        }

        $html = TemplateRenderer::getInstance()->render('@entitysync/preview_rows.html.twig', [
            'rows'     => $rows,
            'readonly' => !$job->isOwnedByCurrentUser(),
            'labels'   => self::fieldLabels(),
        ]);

        return ['html' => $html, 'ids' => $page['ids']] + array_diff_key($page, ['ids' => true]);
    }

    /**
     * Changes the selection of a draft (AJAX).
     *
     * Actions: select / deselect (explicit ids), select_matching / deselect_matching
     * (every operation matching the search, optionally limited to one type, on every page).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function select(array $input): array
    {
        [$job, , $snapshot] = $this->loadDraft((int) ($input['job'] ?? 0));
        if (!$job->isOwnedByCurrentUser()) {
            throw new AccessDeniedHttpException();
        }

        $selection = new Selection($snapshot['operations'], $job->getSelection());
        $ids = array_values(array_map('strval', (array) ($input['ids'] ?? [])));
        $type = isset($input['type']) && in_array($input['type'], Selection::TYPES, true) ? (string) $input['type'] : null;
        $query = (string) ($input['q'] ?? '');

        switch ((string) ($input['action'] ?? '')) {
            case 'select':
                $selection->select($ids);
                break;
            case 'deselect':
                $selection->deselect($ids);
                break;
            case 'select_matching':
                $selection->selectMatching($type, $query);
                break;
            case 'deselect_matching':
                $selection->deselectMatching($type, $query);
                break;
            case 'summary':
                break;
            default:
                throw new BadRequestHttpException();
        }
        $job->saveSelection($selection->ids());

        $visible = array_values(array_map('strval', (array) ($input['visible'] ?? [])));

        return [
            'summary'          => $selection->summary($query),
            'visible_selected' => array_values(array_filter($visible, $selection->isSelected(...))),
        ];
    }

    /**
     * Hands a draft over to the background task.
     */
    public function queue(int $jobId): string
    {
        [$job, $model, $snapshot] = $this->loadDraft($jobId);
        if (!$job->isOwnedByCurrentUser()) {
            throw new AccessDeniedHttpException();
        }

        $selection = new Selection($snapshot['operations'], $job->getSelection());
        if ($selection->ids() === []) {
            Session::addMessageAfterRedirect(__s('No change was selected.', 'entitysync'), false, WARNING);
            return Job::getUrl($jobId);
        }

        $active = Job::findActiveForModel($model->getID(), $jobId);
        if ($active !== null) {
            Session::addMessageAfterRedirect(
                __s('Another import of this model is waiting or running. Wait for it to end before applying this one.', 'entitysync'),
                false,
                WARNING,
            );
            return Job::getUrl($jobId);
        }

        // The background task has no user session: the rights are checked now and kept.
        $job->updateFields([
            'status'      => Job::STATUS_QUEUED,
            'selection'   => json_encode($selection->ids()),
            'forbidden'   => json_encode(GlpiGateway::forbiddenTypes(), JSON_UNESCAPED_UNICODE),
            'nb_total'    => count($selection->ids()),
            'date_queued' => $_SESSION['glpi_currenttime'],
            'results'     => '{}',
        ]);

        return Job::getUrl($jobId);
    }

    /**
     * Cancels a draft (removed), a queued job (cancelled at once) or a running one
     * (stopped at the end of its current batch).
     */
    public function cancel(int $jobId): string
    {
        [$job, $model] = $this->loadJob($jobId);
        if (!$job->isOwnedByCurrentUser() && !Session::haveRight(Model::$rightname, UPDATE)) {
            throw new AccessDeniedHttpException();
        }

        switch ($job->fields['status']) {
            case Job::STATUS_DRAFT:
            case Job::STATUS_FAILED:
                $job->delete(['id' => $job->getID()], true);
                return $model->getLinkURL();
            case Job::STATUS_QUEUED:
                $job->updateFields(['status' => Job::STATUS_CANCELLED, 'date_end' => $_SESSION['glpi_currenttime'], 'selection' => null]);
                JobStorage::remove($job->getID());
                break;
            case Job::STATUS_RUNNING:
                $job->updateFields(['status' => Job::STATUS_CANCELLING]);
                break;
        }

        return Job::getUrl($jobId);
    }

    /**
     * Progress of a job (AJAX).
     *
     * @return array<string, mixed>
     */
    public function status(int $jobId): array
    {
        [$job, $model] = $this->loadJob($jobId);

        return $this->statusData($job, $model);
    }

    private function showStatus(Job $job, Model $model): void
    {
        TemplateRenderer::getInstance()->display('@entitysync/job_status.html.twig', [
            'model'     => $model,
            'job'       => $job,
            'action'    => self::getUrl(),
            'status'    => $this->statusData($job, $model),
            'canCancel' => $job->isOwnedByCurrentUser() || Session::haveRight(Model::$rightname, UPDATE),
            'canConfig' => Session::haveRight('config', UPDATE),
            'statusUrl' => self::getAjaxUrl('job') . '?job=' . $job->getID(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusData(Job $job, Model $model): array
    {
        $f = $job->fields;
        $total = max(0, (int) $f['nb_total']);

        return [
            'id'        => $job->getID(),
            'status'    => (string) $f['status'],
            'active'    => $job->isActive(),
            'total'     => $total,
            'processed' => (int) $f['nb_processed'],
            'percent'   => $total > 0 ? min(100, (int) floor(100 * (int) $f['nb_processed'] / $total)) : ($job->isActive() ? 0 : 100),
            'counts'    => [
                'add'     => (int) $f['nb_added'],
                'link'    => (int) $f['nb_linked'],
                'update'  => (int) $f['nb_updated'],
                'delete'  => (int) $f['nb_deleted'],
                'failed'  => (int) $f['nb_failed'],
                'skipped' => (int) $f['nb_skipped'],
            ],
            'message'   => (string) ($f['message'] ?? ''),
            'dates'     => [
                'queued' => $f['date_queued'],
                'start'  => $f['date_start'],
                'end'    => $f['date_end'],
            ],
            'cron'      => $job->cronHealth(),
            'run_url'   => (int) $f['plugin_entitysync_runs_id'] > 0
                ? $model->getLinkURL() . '&forcetab=' . urlencode(Model::class . '$2') . '&run=' . (int) $f['plugin_entitysync_runs_id']
                : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fieldLabels(): array
    {
        return [
            Operation::FIELD_NAME    => __('Name'),
            Operation::FIELD_COMMENT => __('Comments'),
            Operation::FIELD_PARENT  => __('Parent entity', 'entitysync'),
        ];
    }

    private function loadModel(int $id): Model
    {
        $model = new Model();
        if (!$model->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        if (!$model->can($id, READ) || !Model::canApply()) {
            throw new AccessDeniedHttpException();
        }
        if (!Session::haveRecursiveAccessToEntity($model->getTargetEntityId())) {
            throw new AccessDeniedHttpException(__('You need a recursive access to the target root entity.', 'entitysync'));
        }

        return $model;
    }

    /**
     * @return array{0: Job, 1: Model}
     */
    private function loadJob(int $jobId): array
    {
        $job = new Job();
        if ($jobId <= 0 || !$job->getFromDB($jobId)) {
            Session::addMessageAfterRedirect(__s('This import no longer exists.', 'entitysync'), false, WARNING);
            Html::redirect(Model::getSearchURL());
        }

        return [$job, $this->loadModel((int) $job->fields['plugin_entitysync_models_id'])];
    }

    /**
     * @return array{0: Job, 1: Model, 2: array<string, mixed>}
     */
    private function loadDraft(int $jobId): array
    {
        $job = new Job();
        if ($jobId <= 0 || !$job->getFromDB($jobId)) {
            throw new NotFoundHttpException();
        }
        $model = $this->loadModel((int) $job->fields['plugin_entitysync_models_id']);
        if ($job->fields['status'] !== Job::STATUS_DRAFT) {
            throw new BadRequestHttpException(__('This import is no longer editable.', 'entitysync'));
        }
        $snapshot = JobStorage::readSnapshot($job->getID());
        if ($snapshot === null) {
            throw new NotFoundHttpException();
        }

        return [$job, $model, $snapshot];
    }
}
