<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Config;

use GlpiPlugin\Entitysync\Engine\Csv\CsvReader;
use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Engine\Filter\FilterEvaluator;
use GlpiPlugin\Entitysync\Engine\Util\Msg;

/**
 * Export file of an import model configuration, to copy a model to another GLPI
 * instance or to restore it.
 *
 * JSON format:
 * {
 *   "format": "entitysync-model",
 *   "version": 1,
 *   "plugin_version": "0.0.3",
 *   "exported_at": "2026-10-27T10:00:00+01:00",
 *   "model": {
 *     "name": "HR export",
 *     "comment": "",
 *     "target": "Root entity > Customers",   // complete name of the target root entity, informative
 *     "csv": {"delimiter": "auto", "enclosure": "\"", "encoding": "auto"},
 *     "levels": [...],                         // see LevelConfig
 *     "filter": {...},                         // see FilterEvaluator
 *     "known_columns": ["Org", "Site"]
 *   }
 * }
 *
 * The target root entity is only exported by name: entity ids differ between
 * instances, so the import asks for the target.
 */
final class ModelExport
{
    public const FORMAT = 'entitysync-model';

    /** Version of the file format; files of a newer version are rejected. */
    public const VERSION = 1;

    /** Delimiters accepted besides a single character. */
    private const NAMED_DELIMITERS = [CsvReader::DELIMITER_AUTO, '\t'];

    /**
     * @param array<string, mixed> $model Normalized model: name, comment, target, csv, levels, filter, known_columns.
     *
     * @return array<string, mixed>
     */
    public static function build(array $model, string $pluginVersion, \DateTimeInterface $date): array
    {
        return [
            'format'         => self::FORMAT,
            'version'        => self::VERSION,
            'plugin_version' => $pluginVersion,
            'exported_at'    => $date->format(\DateTimeInterface::ATOM),
            'model'          => [
                'name'          => (string) ($model['name'] ?? ''),
                'comment'       => (string) ($model['comment'] ?? ''),
                'target'        => isset($model['target']) ? (string) $model['target'] : null,
                'csv'           => [
                    'delimiter' => (string) ($model['csv']['delimiter'] ?? CsvReader::DELIMITER_AUTO),
                    'enclosure' => (string) ($model['csv']['enclosure'] ?? '"'),
                    'encoding'  => (string) ($model['csv']['encoding'] ?? CsvReader::ENCODING_AUTO),
                ],
                'levels'        => array_values((array) ($model['levels'] ?? [])),
                'filter'        => (array) ($model['filter'] ?? FilterEvaluator::emptyTree()),
                'known_columns' => array_values(array_map('strval', (array) ($model['known_columns'] ?? []))),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Reads and validates an export file.
     *
     * @param callable(string): string $defaultEmptyLabel See {@see ModelConfig::fromArrays()}.
     *
     * @return array<string, mixed> The normalized model, in the form accepted by {@see build()}.
     *
     * @throws EngineException With every problem found, one per line.
     */
    public static function parse(string $json, callable $defaultEmptyLabel): array
    {
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }
        $document = json_decode($json, true);
        if (!is_array($document)) {
            throw new EngineException(Msg::t('The file is not valid JSON.'));
        }
        if (($document['format'] ?? null) !== self::FORMAT || !is_array($document['model'] ?? null)) {
            throw new EngineException(Msg::t('The file is not an import model export.'));
        }
        $version = $document['version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > self::VERSION) {
            throw new EngineException(Msg::t('Unsupported version of the export format: %s.', is_scalar($version) ? (string) $version : '?'));
        }

        $model = $document['model'];
        $errors = [];

        $name = trim(is_string($model['name'] ?? null) ? $model['name'] : '');
        $comment = is_string($model['comment'] ?? null) ? $model['comment'] : '';
        $target = is_string($model['target'] ?? null) && $model['target'] !== '' ? $model['target'] : null;

        $csv = is_array($model['csv'] ?? null) ? $model['csv'] : [];
        $delimiter = (string) ($csv['delimiter'] ?? CsvReader::DELIMITER_AUTO);
        if (!in_array($delimiter, self::NAMED_DELIMITERS, true) && mb_strlen($delimiter) !== 1) {
            $errors[] = Msg::t('Invalid CSV delimiter "%s".', $delimiter);
        }
        $enclosure = (string) ($csv['enclosure'] ?? '"');
        if (mb_strlen($enclosure) !== 1) {
            $errors[] = Msg::t('Invalid CSV text delimiter "%s".', $enclosure);
        }
        $encoding = (string) ($csv['encoding'] ?? CsvReader::ENCODING_AUTO);
        if (!in_array($encoding, CsvReader::SUPPORTED_ENCODINGS, true)) {
            $errors[] = Msg::t('Unsupported CSV encoding "%s".', $encoding);
        }

        $levels = $model['levels'] ?? null;
        if (!is_array($levels) || !array_is_list($levels)) {
            $errors[] = Msg::t('The levels must be a list.');
            $levels = [];
        }
        $filter = $model['filter'] ?? null;
        if ($filter === null || $filter === []) {
            $filter = FilterEvaluator::emptyTree();
        } elseif (!is_array($filter)) {
            $errors[] = Msg::t('The filter must be an object.');
            $filter = FilterEvaluator::emptyTree();
        }
        $config = ModelConfig::fromArrays(0, 0, $levels, $filter, $defaultEmptyLabel);
        array_push($errors, ...$config->validate());

        $columns = [];
        foreach ((array) ($model['known_columns'] ?? []) as $column) {
            if (is_string($column) && $column !== '') {
                $columns[] = $column;
            }
        }

        if ($errors !== []) {
            throw new EngineException(implode("\n", $errors));
        }

        return [
            'name'          => $name,
            'comment'       => $comment,
            'target'        => $target,
            'csv'           => ['delimiter' => $delimiter, 'enclosure' => $enclosure, 'encoding' => $encoding],
            'levels'        => $levels,
            'filter'        => $filter,
            'known_columns' => array_values(array_unique($columns)),
        ];
    }
}
