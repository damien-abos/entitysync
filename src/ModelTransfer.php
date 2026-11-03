<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use Entity;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Entitysync\Engine\Config\ModelExport;
use GlpiPlugin\Entitysync\Engine\EngineException;
use Session;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export of a model configuration to a JSON file, and import of such a file as a
 * new model or into an existing one ({@see ModelExport} for the format).
 */
final class ModelTransfer
{
    public const MODE_NEW = 'new';
    public const MODE_REPLACE = 'replace';

    /** Largest export file accepted, in bytes. */
    private const MAX_SIZE = 1024 * 1024;

    public static function getUrl(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/entitysync/front/model.transfer.php';
    }

    public static function getExportUrl(int $modelId): string
    {
        return self::getUrl() . '?export=' . $modelId;
    }

    public static function canImport(): bool
    {
        return Model::canCreate() || Model::canUpdate();
    }

    /**
     * Downloads the configuration of a model.
     */
    public function export(int $modelId): Response
    {
        $model = new Model();
        if (!$model->getFromDB($modelId)) {
            throw new NotFoundHttpException();
        }
        if (!$model->can($modelId, READ)) {
            throw new AccessDeniedHttpException();
        }

        $target = new Entity();
        $targetName = $target->getFromDB($model->getTargetEntityId()) ? (string) $target->fields['completename'] : null;

        $document = ModelExport::build([
            'name'          => (string) $model->fields['name'],
            'comment'       => (string) ($model->fields['comment'] ?? ''),
            'target'        => $targetName,
            'csv'           => [
                'delimiter' => (string) $model->fields['csv_delimiter'],
                'enclosure' => (string) $model->fields['csv_enclosure'],
                'encoding'  => (string) $model->fields['csv_encoding'],
            ],
            'levels'        => $model->getLevels(),
            'filter'        => $model->getFilter(),
            'known_columns' => $model->getKnownColumns(),
        ], PLUGIN_ENTITYSYNC_VERSION, new \DateTimeImmutable());

        $ascii = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', (string) $model->fields['name']);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-');
        $filename = 'entitysync-model-' . ($slug !== '' ? $slug : $modelId) . '.json';

        return new Response(ModelExport::encode($document), 200, [
            'Content-Type'        => 'application/json; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Displays the import form; `$modelId` preselects the model to replace.
     */
    public function showForm(int $modelId): void
    {
        if (!self::canImport()) {
            throw new AccessDeniedHttpException();
        }

        $models = [];
        foreach ((new Model())->find([], ['name']) as $row) {
            $model = new Model();
            $model->getFromResultSet($row);
            if ($model->can($model->getID(), UPDATE)) {
                $models[$model->getID()] = (string) $row['name'];
            }
        }

        TemplateRenderer::getInstance()->display('@entitysync/model_import.html.twig', [
            'action'     => self::getUrl(),
            'can_create' => Model::canCreate(),
            'models'     => $models,
            'models_id'  => isset($models[$modelId]) ? $modelId : 0,
            'mode'       => isset($models[$modelId]) || !Model::canCreate() ? self::MODE_REPLACE : self::MODE_NEW,
            'max_size'   => \Toolbox::getSize(self::MAX_SIZE),
        ]);
    }

    /**
     * Imports an uploaded export file and returns the URL to redirect to.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function import(array $post, array $files): string
    {
        $back = self::getUrl();
        $mode = (string) ($post['mode'] ?? self::MODE_NEW);
        $modelId = (int) ($post['models_id'] ?? 0);
        if ($mode === self::MODE_REPLACE) {
            $back .= '?models_id=' . $modelId;
        }

        $file = $files['model_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            Session::addMessageAfterRedirect(__s('No file was received.', 'entitysync'), false, ERROR);
            return $back;
        }
        if ((int) filesize((string) $file['tmp_name']) > self::MAX_SIZE) {
            Session::addMessageAfterRedirect(__s('The file is too large.', 'entitysync'), false, ERROR);
            return $back;
        }

        try {
            $config = ModelExport::parse((string) file_get_contents((string) $file['tmp_name']), Model::defaultEmptyLabel(...));
        } catch (EngineException $e) {
            Session::addMessageAfterRedirect(
                __s('The file cannot be imported:', 'entitysync') . '<br>' . nl2br(htmlescape($e->getMessage())),
                false,
                ERROR,
            );
            return $back;
        }

        $input = [
            'csv_delimiter' => $config['csv']['delimiter'],
            'csv_enclosure' => $config['csv']['enclosure'],
            'csv_encoding'  => $config['csv']['encoding'],
            'levels_json'   => json_encode($config['levels'], JSON_UNESCAPED_UNICODE),
            'filter_json'   => json_encode($config['filter'], JSON_UNESCAPED_UNICODE),
        ];

        if ($mode === self::MODE_REPLACE) {
            $model = new Model();
            if (!$model->getFromDB($modelId)) {
                throw new NotFoundHttpException();
            }
            if (!$model->can($modelId, UPDATE)) {
                throw new AccessDeniedHttpException();
            }
            if ($model->getKnownColumns() === []) {
                $input['known_columns'] = json_encode($config['known_columns'], JSON_UNESCAPED_UNICODE);
            }
            if (!$model->update(['id' => $modelId] + $input)) {
                return $back;
            }
            Session::addMessageAfterRedirect(__s('The configuration of the model was replaced.', 'entitysync'));

            return $model->getLinkURL();
        }

        if (!Model::canCreate()) {
            throw new AccessDeniedHttpException();
        }
        $target = (int) ($post['entities_id_target'] ?? -1);
        if ($target < 0 || !Session::haveAccessToEntity($target)) {
            Session::addMessageAfterRedirect(__s('Select a target root entity you have access to.', 'entitysync'), false, ERROR);
            return $back;
        }
        $name = trim((string) ($post['name'] ?? ''));

        $model = new Model();
        $id = $model->add($input + [
            'name'               => $name !== '' ? $name : ($config['name'] !== '' ? $config['name'] : __('Imported model', 'entitysync')),
            'comment'            => $config['comment'],
            'entities_id_target' => $target,
            'known_columns'      => json_encode($config['known_columns'], JSON_UNESCAPED_UNICODE),
        ]);
        if (!$id) {
            return $back;
        }
        if ($config['target'] !== null) {
            $chosen = new Entity();
            $chosen->getFromDB($target);
            if (($chosen->fields['completename'] ?? null) !== $config['target']) {
                Session::addMessageAfterRedirect(
                    htmlescape(sprintf(__('The exported model targeted "%s": check the target root entity.', 'entitysync'), $config['target'])),
                    false,
                    WARNING,
                );
            }
        }

        return $model->getLinkURL();
    }
}
