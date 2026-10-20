<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use CommonDBTM;
use CommonGLPI;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Entitysync\Engine\Config\ModelConfig;
use GlpiPlugin\Entitysync\Engine\Csv\CsvReader;
use GlpiPlugin\Entitysync\Engine\Filter\FilterEvaluator;
use Log;
use Session;

/**
 * Import model: target root entity, CSV options, levels and filter.
 */
class Model extends CommonDBTM
{
    public static $rightname = 'plugin_entitysync_model';

    /** Right to apply an import (in addition to entity rights). */
    public const APPLY = 128;

    public const ALL_RIGHTS = READ | CREATE | UPDATE | PURGE | self::APPLY;

    public $dohistory = true;

    private const TAB_IMPORT = 1;
    private const TAB_HISTORY = 2;
    private const TAB_MANAGED = 3;

    public static function getTypeName($nb = 0)
    {
        return _n('Entity import model', 'Entity import models', $nb, 'entitysync');
    }

    public static function getIcon()
    {
        return 'ti ti-sitemap';
    }

    public static function getMenuName()
    {
        return __('Entity import', 'entitysync');
    }

    public static function canApply(): bool
    {
        return (bool) Session::haveRight(self::$rightname, self::APPLY);
    }

    public function getRights($interface = 'central')
    {
        $rights = parent::getRights($interface);
        $rights[self::APPLY] = __('Apply an import', 'entitysync');

        return $rights;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(self::class, $tabs, $options);
        $this->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof self || $item->isNewItem()) {
            return '';
        }

        $tabs = [];
        if (self::canApply()) {
            $tabs[self::TAB_IMPORT] = self::createTabEntry(__('Import', 'entitysync'), 0, null, 'ti ti-file-import');
        }
        $tabs[self::TAB_MANAGED] = self::createTabEntry(
            Link::getTypeName(Session::getPluralNumber()),
            $_SESSION['glpishow_count_on_tabs'] ? Link::countForModel($item->getID()) : 0,
            null,
            'ti ti-link',
        );
        $tabs[self::TAB_HISTORY] = self::createTabEntry(
            Run::getTypeName(Session::getPluralNumber()),
            $_SESSION['glpishow_count_on_tabs'] ? Run::countForModel($item->getID()) : 0,
            null,
            Run::getIcon(),
        );

        return $tabs;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof self) {
            return false;
        }

        switch ((int) $tabnum) {
            case self::TAB_IMPORT:
                $item->showImportForm();
                break;
            case self::TAB_MANAGED:
                $item->showManagedEntities();
                break;
            case self::TAB_HISTORY:
                Run::showForModel($item);
                break;
        }

        return true;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@entitysync/model_form.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'levels'        => $this->getLevels(),
            'filter'        => $this->getFilter(),
            'known_columns' => $this->getKnownColumns(),
            'encodings'     => array_combine(CsvReader::SUPPORTED_ENCODINGS, array_map(
                static fn (string $e): string => $e === 'auto' ? __('Automatic', 'entitysync') : $e,
                CsvReader::SUPPORTED_ENCODINGS,
            )),
            'delimiters'    => [
                'auto' => __('Automatic', 'entitysync'),
                ';'    => __('Semicolon (;)', 'entitysync'),
                ','    => __('Comma (,)', 'entitysync'),
                '\t'   => __('Tab', 'entitysync'),
                '|'    => __('Pipe (|)', 'entitysync'),
            ],
            'js_labels'     => self::jsLabels(),
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->prepareConfigInput($input);
        if ($input === false) {
            return false;
        }
        $input['levels'] ??= '[]';
        $input['filter'] ??= json_encode(FilterEvaluator::emptyTree());

        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->prepareConfigInput($input);
        if ($input === false) {
            return false;
        }

        return parent::prepareInputForUpdate($input);
    }

    /**
     * Decodes and validates the JSON produced by the levels and filter editors.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>|false
     */
    private function prepareConfigInput(array $input): array|false
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__s('The name is mandatory.', 'entitysync'), false, ERROR);
            return false;
        }

        $levels = null;
        if (isset($input['levels_json'])) {
            $levels = json_decode((string) $input['levels_json'], true);
            if (!is_array($levels)) {
                Session::addMessageAfterRedirect(__s('The levels configuration is not valid JSON.', 'entitysync'), false, ERROR);
                return false;
            }
            $input['levels'] = json_encode(array_values($levels), JSON_UNESCAPED_UNICODE);
        }
        unset($input['levels_json']);

        $filter = null;
        if (isset($input['filter_json'])) {
            $filter = json_decode((string) $input['filter_json'], true);
            if (!is_array($filter)) {
                Session::addMessageAfterRedirect(__s('The filter configuration is not valid JSON.', 'entitysync'), false, ERROR);
                return false;
            }
            $input['filter'] = json_encode($filter, JSON_UNESCAPED_UNICODE);
        }
        unset($input['filter_json']);

        if ($levels !== null || $filter !== null) {
            $config = ModelConfig::fromArrays(
                0,
                0,
                $levels ?? $this->getLevels(),
                $filter ?? $this->getFilter(),
                self::defaultEmptyLabel(...),
            );
            $errors = $config->validate();
            if ($errors !== []) {
                foreach ($errors as $error) {
                    Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
                }
                return false;
            }
        }

        if (isset($input['csv_encoding']) && !in_array($input['csv_encoding'], CsvReader::SUPPORTED_ENCODINGS, true)) {
            $input['csv_encoding'] = CsvReader::ENCODING_AUTO;
        }
        if (isset($input['csv_enclosure']) && $input['csv_enclosure'] === '') {
            $input['csv_enclosure'] = '"';
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        $DB->delete(Link::getTable(), ['plugin_entitysync_models_id' => $this->getID()]);
        $DB->delete(Run::getTable(), ['plugin_entitysync_models_id' => $this->getID()]);

        $job = new Job();
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Job::getTable(), 'WHERE' => ['plugin_entitysync_models_id' => $this->getID()]]) as $row) {
            $job->delete(['id' => $row['id']], true);
        }
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => '2',
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'massiveaction' => false,
            'datatype'      => 'number',
        ];
        $tab[] = [
            'id'            => '3',
            'table'         => Entity::getTable(),
            'field'         => 'completename',
            'linkfield'     => 'entities_id_target',
            'name'          => __('Target root entity', 'entitysync'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id'            => '19',
            'table'         => self::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '121',
            'table'         => self::getTable(),
            'field'         => 'date_creation',
            'name'          => __('Creation date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLevels(): array
    {
        $levels = json_decode((string) ($this->fields['levels'] ?? '[]'), true);

        return is_array($levels) ? array_values($levels) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilter(): array
    {
        $filter = json_decode((string) ($this->fields['filter'] ?? ''), true);

        return is_array($filter) && $filter !== [] ? $filter : FilterEvaluator::emptyTree();
    }

    /**
     * @return list<string>
     */
    public function getKnownColumns(): array
    {
        $columns = json_decode((string) ($this->fields['known_columns'] ?? '[]'), true);

        return is_array($columns) ? array_values(array_map('strval', $columns)) : [];
    }

    /**
     * Remembers the header of the last imported file, to suggest columns in the editors.
     *
     * @param list<string> $columns
     */
    public function rememberColumns(array $columns): void
    {
        global $DB;

        $DB->update(self::getTable(), ['known_columns' => json_encode($columns, JSON_UNESCAPED_UNICODE)], ['id' => $this->getID()]);
        $this->fields['known_columns'] = json_encode($columns, JSON_UNESCAPED_UNICODE);
    }

    public function getTargetEntityId(): int
    {
        return (int) ($this->fields['entities_id_target'] ?? 0);
    }

    public function toEngineConfig(): ModelConfig
    {
        return ModelConfig::fromArrays(
            $this->getID(),
            $this->getTargetEntityId(),
            $this->getLevels(),
            $this->getFilter(),
            self::defaultEmptyLabel(...),
        );
    }

    public function csvReader(): CsvReader
    {
        return new CsvReader(
            (string) ($this->fields['csv_delimiter'] ?? CsvReader::DELIMITER_AUTO),
            (string) ($this->fields['csv_enclosure'] ?? '"'),
            (string) ($this->fields['csv_encoding'] ?? CsvReader::ENCODING_AUTO),
        );
    }

    /**
     * Default name of the placeholder entity created for an empty mid-path value.
     */
    public static function defaultEmptyLabel(string $levelLabel): string
    {
        return sprintf(__('No %s', 'entitysync'), mb_strtolower($levelLabel, 'UTF-8'));
    }

    private function showImportForm(): void
    {
        $target = new Entity();
        $target->getFromDB($this->getTargetEntityId());

        TemplateRenderer::getInstance()->display('@entitysync/import_form.html.twig', [
            'item'          => $this,
            'action'        => ImportController::getUrl(),
            'target_name'   => $target->isNewItem() ? null : $target->fields['completename'],
            'levels'        => $this->toEngineConfig()->levels,
            'has_recursive' => Session::haveRecursiveAccessToEntity($this->getTargetEntityId()),
            'max_upload'    => \Toolbox::getSize(\Toolbox::return_bytes_from_ini_vars(ini_get('upload_max_filesize'))),
            'jobs'          => Job::listOpenForModel($this->getID()),
        ]);
    }

    private function showManagedEntities(): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                Link::getTable() . '.entities_id',
                Link::getTable() . '.path',
                Link::getTable() . '.date_creation',
                Entity::getTable() . '.completename',
            ],
            'FROM'      => Link::getTable(),
            'LEFT JOIN' => [
                Entity::getTable() => [
                    'ON' => [Link::getTable() => 'entities_id', Entity::getTable() => 'id'],
                ],
            ],
            'WHERE'     => [Link::getTable() . '.plugin_entitysync_models_id' => $this->getID()],
            'ORDER'     => [Entity::getTable() . '.completename'],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = [
                'entity_id'    => (int) $row['entities_id'],
                'completename' => $row['completename'],
                'keys'         => Engine\Tree\Path::displayKeys(Engine\Tree\Path::segments((string) $row['path'])),
                'date'         => $row['date_creation'],
            ];
        }

        TemplateRenderer::getInstance()->display('@entitysync/managed_entities.html.twig', [
            'rows'       => $rows,
            'entity_url' => Entity::getFormURL(),
        ]);
    }

    /**
     * Labels used by the JavaScript editors.
     *
     * @return array<string, string>
     */
    public static function jsLabels(): array
    {
        return [
            'level'              => __('Level', 'entitysync'),
            'level_label'        => __('Level label', 'entitysync'),
            'add_level'          => __('Add a level', 'entitysync'),
            'remove'             => __('Remove', 'entitysync'),
            'move_up'            => __('Move up', 'entitysync'),
            'move_down'          => __('Move down', 'entitysync'),
            'key_columns'        => __('Key columns', 'entitysync'),
            'key_columns_help'   => __('One or more columns identifying the entity at this level. An empty key mid-path creates the "empty" entity below.', 'entitysync'),
            'name_column'        => __('Name column', 'entitysync'),
            'name_column_help'   => __('Leave empty to use the key columns.', 'entitysync'),
            'name_rules'         => __('Name replacement rules', 'entitysync'),
            'name_rules_help'    => __('Applied in order. Regular expressions without delimiters; use $1, $2... in the replacement.', 'entitysync'),
            'pattern'            => __('Pattern', 'entitysync'),
            'replacement'        => __('Replacement', 'entitysync'),
            'flags'              => __('Flags', 'entitysync'),
            'add_rule'           => __('Add a rule', 'entitysync'),
            'comment_columns'    => __('Comment columns', 'entitysync'),
            'comment_columns_help' => __('Each column adds a "Label: value" line. Without columns, the comment of the entities is left untouched.', 'entitysync'),
            'column'             => __('Column', 'entitysync'),
            'label'              => __('Label', 'entitysync'),
            'add_comment_column' => __('Add a column', 'entitysync'),
            'skip_empty'         => __('Skip empty values', 'entitysync'),
            'empty_label'        => __('Name when empty', 'entitysync'),
            'empty_label_help'   => __('Name of the entity created when this level is empty but a deeper level is filled. Default: "No <level>".', 'entitysync'),
            'add_column'         => __('Add', 'entitysync'),
            'test_rules'         => __('Test', 'entitysync'),
            'test_value'         => __('Sample value', 'entitysync'),
            'group'              => __('Group', 'entitysync'),
            'and'                => __('AND', 'entitysync'),
            'or'                 => __('OR', 'entitysync'),
            'not'                => __('NOT', 'entitysync'),
            'add_condition'      => __('Add a condition', 'entitysync'),
            'add_group'          => __('Add a group', 'entitysync'),
            'values'             => __('Value(s)', 'entitysync'),
            'values_help'        => __('One value per line. With several values, the condition matches if any value matches (or none, for negative operators).', 'entitysync'),
            'case_sensitive'     => __('Case sensitive', 'entitysync'),
            'empty_filter'       => __('No condition: every row is imported.', 'entitysync'),
            'op_eq'              => __('equals', 'entitysync'),
            'op_neq'             => __('is different from', 'entitysync'),
            'op_in'              => __('is in the list', 'entitysync'),
            'op_not_in'          => __('is not in the list', 'entitysync'),
            'op_contains'        => __('contains', 'entitysync'),
            'op_not_contains'    => __('does not contain', 'entitysync'),
            'op_starts_with'     => __('starts with', 'entitysync'),
            'op_ends_with'       => __('ends with', 'entitysync'),
            'op_regex'           => __('matches the regular expression', 'entitysync'),
            'op_gt'              => __('is greater than', 'entitysync'),
            'op_gte'             => __('is greater than or equal to', 'entitysync'),
            'op_lt'              => __('is less than', 'entitysync'),
            'op_lte'             => __('is less than or equal to', 'entitysync'),
            'op_empty'           => __('is empty', 'entitysync'),
            'op_not_empty'       => __('is not empty', 'entitysync'),
            'no_levels'          => __('No level yet: add the first level below the target root entity.', 'entitysync'),
            'invalid_regex'      => __('Invalid regular expression', 'entitysync'),
            'no_match'           => __('(no change)', 'entitysync'),
            'default_empty'      => __('No %s', 'entitysync'),
            'regex_test_note'    => __('Preview computed by your browser; the server uses PCRE, results may differ for advanced syntax.', 'entitysync'),
        ];
    }
}
