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
use GlpiPlugin\Entitysync\Engine\Csv\CsvData;
use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Engine\Sync\Applier;
use GlpiPlugin\Entitysync\Engine\Sync\Operation;
use GlpiPlugin\Entitysync\Engine\Sync\Plan;
use GlpiPlugin\Entitysync\Engine\Sync\Planner;
use GlpiPlugin\Entitysync\Engine\Tree\TreeBuilder;
use Html;
use Session;

/**
 * Import workflow: upload → preview (selection panel) → apply.
 *
 * The uploaded file is kept in GLPI's temporary directory between the preview and
 * the application; the plan is recomputed against the current GLPI state when the
 * selection is applied, so changes made in between are taken into account.
 */
final class ImportController
{
    private const SESSION_KEY = 'plugin_entitysync_uploads';

    /** Uploads older than this are forgotten (seconds). */
    private const UPLOAD_TTL = 86400;

    public static function getUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/entitysync/front/import.php';
    }

    /**
     * Stores the uploaded file and returns the preview URL.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function upload(array $post, array $files): string
    {
        $model = $this->loadModel((int) ($post['models_id'] ?? 0));
        self::purgeExpiredUploads();

        $file = $files['csv_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            Session::addMessageAfterRedirect(__s('No file was received.', 'entitysync'), false, ERROR);
            return $model->getLinkURL();
        }

        $token = bin2hex(random_bytes(16));
        $path = GLPI_TMP_DIR . '/entitysync_' . $token . '.csv';
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
            Session::addMessageAfterRedirect(__s('The file could not be stored.', 'entitysync'), false, ERROR);
            return $model->getLinkURL();
        }

        $_SESSION[self::SESSION_KEY][$token] = [
            'models_id' => $model->getID(),
            'path'      => $path,
            'filename'  => basename((string) ($file['name'] ?? 'import.csv')),
            'time'      => time(),
        ];

        return self::getUrl() . '?token=' . $token;
    }

    /**
     * Displays the selection panel.
     */
    public function preview(string $token): void
    {
        [$model, $upload] = $this->loadUpload($token);

        try {
            $csv = $model->csvReader()->readFile($upload['path']);
            $model->rememberColumns($csv->columns);
            $plan = $this->computePlan($model, $csv);
        } catch (EngineException $e) {
            TemplateRenderer::getInstance()->display('@entitysync/preview_error.html.twig', [
                'model'    => $model,
                'filename' => $upload['filename'],
                'errors'   => explode("\n", $e->getMessage()),
            ]);
            return;
        }

        $sections = [];
        foreach ([Operation::TYPE_ADD, Operation::TYPE_LINK, Operation::TYPE_UPDATE, Operation::TYPE_DELETE] as $type) {
            $sections[$type] = array_map(static fn (Operation $op): array => $op->toArray(), $plan->ofType($type));
        }

        TemplateRenderer::getInstance()->display('@entitysync/preview.html.twig', [
            'model'     => $model,
            'token'     => $token,
            'action'    => self::getUrl(),
            'filename'  => $upload['filename'],
            'csv'       => [
                'columns'   => $csv->columns,
                'encoding'  => $csv->detectedEncoding,
                'delimiter' => $csv->detectedDelimiter === "\t" ? 'TAB' : $csv->detectedDelimiter,
            ],
            'stats'     => [
                'rows'     => $plan->build->totalRows,
                'filtered' => $plan->build->filteredOutRows,
                'invalid'  => $plan->build->invalidRows,
                'nodes'    => count($plan->build->nodes),
            ],
            'counts'    => $plan->counts(),
            'sections'  => $sections,
            'issues'    => array_map(static fn ($issue) => $issue->toArray(), $plan->issues),
            'labels'    => [
                'name'    => __('Name'),
                'comment' => __('Comments'),
                'parent'  => __('Parent entity', 'entitysync'),
            ],
        ]);
    }

    /**
     * Applies the selected operations and returns the URL to redirect to.
     *
     * @param array<string, mixed> $post
     */
    public function apply(array $post): string
    {
        $token = (string) ($post['token'] ?? '');
        [$model, $upload] = $this->loadUpload($token);

        $selected = array_values(array_filter(
            array_map('strval', (array) ($post['ops'] ?? [])),
            static fn (string $id): bool => preg_match('/^[0-9a-f]{16}$/', $id) === 1,
        ));
        if ($selected === []) {
            Session::addMessageAfterRedirect(__s('No change was selected.', 'entitysync'), false, WARNING);
            return self::getUrl() . '?token=' . $token;
        }

        try {
            $csv = $model->csvReader()->readFile($upload['path']);
            $plan = $this->computePlan($model, $csv);
        } catch (EngineException $e) {
            Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
            return self::getUrl() . '?token=' . $token;
        }

        // Large imports create entities one by one through GLPI: do not stop half-way.
        @set_time_limit(0);
        $report = (new Applier(new GlpiGateway($model->getID())))->apply($plan, $selected);
        $runId = Run::record($model, $upload['filename'], $plan->build->totalRows, count($selected), $report);

        $this->forgetUpload($token);
        $this->addSummaryMessage($report);

        return $model->getLinkURL() . '&forcetab=' . urlencode(Model::class . '$2') . '&run=' . $runId;
    }

    /**
     * Drops an upload without applying it.
     */
    public function cancel(string $token): string
    {
        [$model] = $this->loadUpload($token);
        $this->forgetUpload($token);

        return $model->getLinkURL();
    }

    /**
     * @throws EngineException
     */
    private function computePlan(Model $model, CsvData $csv): Plan
    {
        $config = $model->toEngineConfig();
        $errors = $config->validate($csv->columns);
        if ($errors !== []) {
            throw new EngineException(implode("\n", $errors));
        }

        $gateway = new GlpiGateway($model->getID());
        $build = (new TreeBuilder($config))->build($csv);
        $planner = new Planner(
            $config->rootEntityId,
            $gateway->inspectUsage(...),
            GlpiGateway::forbiddenTypes(),
        );

        return $planner->plan($build, $gateway->loadState($config->rootEntityId));
    }

    private function loadModel(int $id): Model
    {
        $model = new Model();
        if (!$model->getFromDB($id)) {
            throw new BadRequestHttpException();
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
     * @return array{0: Model, 1: array{models_id: int, path: string, filename: string, time: int}}
     */
    private function loadUpload(string $token): array
    {
        $upload = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!is_array($upload) || !is_file($upload['path'])) {
            Session::addMessageAfterRedirect(__s('This import has expired or was already applied. Please upload the file again.', 'entitysync'), false, WARNING);
            Html::redirect(Model::getSearchURL());
        }

        return [$this->loadModel((int) $upload['models_id']), $upload];
    }

    private function forgetUpload(string $token): void
    {
        $path = $_SESSION[self::SESSION_KEY][$token]['path'] ?? null;
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
        unset($_SESSION[self::SESSION_KEY][$token]);
    }

    private static function purgeExpiredUploads(): void
    {
        foreach ($_SESSION[self::SESSION_KEY] ?? [] as $token => $upload) {
            if (($upload['time'] ?? 0) < time() - self::UPLOAD_TTL) {
                if (is_file($upload['path'] ?? '')) {
                    @unlink($upload['path']);
                }
                unset($_SESSION[self::SESSION_KEY][$token]);
            }
        }
    }

    /**
     * @param array{counts: array<string, int>, auto_selected: list<string>, unknown: list<string>} $report
     */
    private function addSummaryMessage(array $report): void
    {
        $counts = $report['counts'];
        $message = sprintf(
            __('Import applied: %1$d added, %2$d linked, %3$d updated, %4$d deleted.', 'entitysync'),
            $counts['add'],
            $counts['link'],
            $counts['update'],
            $counts['delete'],
        );
        $type = INFO;
        if ($counts['failed'] > 0 || $counts['skipped'] > 0) {
            $message .= ' ' . sprintf(
                __('%1$d failed, %2$d skipped: see the run details.', 'entitysync'),
                $counts['failed'],
                $counts['skipped'],
            );
            $type = WARNING;
        }
        if ($report['auto_selected'] !== []) {
            $message .= ' ' . sprintf(
                __('%d required operation(s) were added to your selection.', 'entitysync'),
                count($report['auto_selected']),
            );
        }
        if ($report['unknown'] !== []) {
            $message .= ' ' . sprintf(
                __('%d selected operation(s) no longer apply because the data changed since the preview.', 'entitysync'),
                count($report['unknown']),
            );
            $type = WARNING;
        }

        Session::addMessageAfterRedirect(htmlescape($message), false, $type);
    }
}
