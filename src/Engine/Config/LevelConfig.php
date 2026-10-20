<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Config;

use GlpiPlugin\Entitysync\Engine\Util\Msg;
use GlpiPlugin\Entitysync\Engine\Util\Text;

/**
 * Configuration of one level of the entity tree below the target root.
 *
 * JSON format:
 * {
 *   "label": "Site",
 *   "key_columns": ["Site"],              // one or more columns forming the level key
 *   "name_column": "Site label",          // optional, defaults to the key columns
 *   "name_rules": [{"pattern": "^SITE-", "replacement": "", "flags": "i"}],
 *   "comment_columns": [{"column": "Manager", "label": "Manager"}],
 *   "comment_skip_empty": true,
 *   "empty_label": "No site"              // optional name used when the key is empty mid-path
 * }
 */
final class LevelConfig
{
    /** Separator used to join composite key parts in the internal key. */
    public const KEY_PART_SEPARATOR = "\u{1F}";

    /** Separator used to build the default name from composite key parts. */
    public const NAME_PART_SEPARATOR = ' - ';

    /**
     * @param list<string>                                                        $keyColumns
     * @param list<array{pattern: string, replacement: string, flags: string}>    $nameRules
     * @param list<array{column: string, label: string}>                          $commentColumns
     */
    public function __construct(
        public readonly string $label,
        public readonly array $keyColumns,
        public readonly ?string $nameColumn,
        public readonly array $nameRules,
        public readonly array $commentColumns,
        public readonly bool $commentSkipEmpty,
        public readonly string $emptyLabel,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param string               $defaultEmptyLabel Used when `empty_label` is not set.
     */
    public static function fromArray(array $data, string $defaultEmptyLabel): self
    {
        $keyColumns = array_values(array_filter(
            array_map(static fn ($c): string => trim((string) $c), (array) ($data['key_columns'] ?? [])),
            static fn (string $c): bool => $c !== '',
        ));

        $nameRules = [];
        foreach ((array) ($data['name_rules'] ?? []) as $rule) {
            if (!is_array($rule) || (string) ($rule['pattern'] ?? '') === '') {
                continue;
            }
            $nameRules[] = [
                'pattern'     => (string) $rule['pattern'],
                'replacement' => (string) ($rule['replacement'] ?? ''),
                'flags'       => (string) ($rule['flags'] ?? ''),
            ];
        }

        $commentColumns = [];
        foreach ((array) ($data['comment_columns'] ?? []) as $item) {
            if (!is_array($item) || trim((string) ($item['column'] ?? '')) === '') {
                continue;
            }
            $column = trim((string) $item['column']);
            $label = trim((string) ($item['label'] ?? ''));
            $commentColumns[] = ['column' => $column, 'label' => $label === '' ? $column : $label];
        }

        $nameColumn = trim((string) ($data['name_column'] ?? ''));
        $emptyLabel = Text::cleanName((string) ($data['empty_label'] ?? ''));

        return new self(
            label: trim((string) ($data['label'] ?? '')),
            keyColumns: $keyColumns,
            nameColumn: $nameColumn === '' ? null : $nameColumn,
            nameRules: $nameRules,
            commentColumns: $commentColumns,
            commentSkipEmpty: (bool) ($data['comment_skip_empty'] ?? true),
            emptyLabel: $emptyLabel === '' ? $defaultEmptyLabel : $emptyLabel,
        );
    }

    /**
     * @return list<string> Columns read by this level.
     */
    public function referencedColumns(): array
    {
        $columns = $this->keyColumns;
        if ($this->nameColumn !== null) {
            $columns[] = $this->nameColumn;
        }
        foreach ($this->commentColumns as $item) {
            $columns[] = $item['column'];
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param list<string>|null $availableColumns
     *
     * @return list<string>
     */
    public function validate(int $position, ?array $availableColumns = null): array
    {
        $errors = [];
        $name = $this->label !== '' ? $this->label : (string) $position;

        if ($this->keyColumns === []) {
            $errors[] = Msg::t('Level "%s": at least one key column is required.', $name);
        }

        if ($availableColumns !== null) {
            foreach ($this->referencedColumns() as $column) {
                if (!in_array($column, $availableColumns, true)) {
                    $errors[] = Msg::t('Level "%1$s": column "%2$s" does not exist in the file.', $name, $column);
                }
            }
        }

        foreach ($this->nameRules as $rule) {
            $error = Text::validateRegex($rule['pattern'], $rule['flags']);
            if ($error !== null) {
                $errors[] = Msg::t(
                    'Level "%1$s": invalid regular expression "%2$s" (%3$s).',
                    $name,
                    $rule['pattern'],
                    $error,
                );
            }
        }

        return $errors;
    }

    /**
     * Internal key of the level for a row; empty string when every key part is empty.
     *
     * @param array<string, string> $row
     */
    public function keyOf(array $row): string
    {
        $parts = $this->keyParts($row);

        return implode('', $parts) === '' ? '' : implode(self::KEY_PART_SEPARATOR, $parts);
    }

    /**
     * @param array<string, string> $row
     *
     * @return list<string>
     */
    public function keyParts(array $row): array
    {
        return array_map(static fn (string $column): string => trim($row[$column] ?? ''), $this->keyColumns);
    }

    /**
     * Raw (pre-rules) name of the level for a row.
     *
     * @param array<string, string> $row
     */
    public function rawNameOf(array $row): string
    {
        if ($this->nameColumn !== null) {
            $value = trim($row[$this->nameColumn] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return implode(
            self::NAME_PART_SEPARATOR,
            array_filter($this->keyParts($row), static fn (string $part): bool => $part !== ''),
        );
    }

    /**
     * Applies the replacement rules in sequence.
     */
    public function applyNameRules(string $name): string
    {
        foreach ($this->nameRules as $rule) {
            $result = @preg_replace(Text::buildRegex($rule['pattern'], $rule['flags']), $rule['replacement'], $name);
            if ($result !== null) {
                $name = $result;
            }
        }

        return Text::cleanName($name);
    }

    /**
     * Builds the comment of the level for a row; null when comments are not managed.
     *
     * @param array<string, string> $row
     */
    public function commentOf(array $row): ?string
    {
        if ($this->commentColumns === []) {
            return null;
        }

        $lines = [];
        foreach ($this->commentColumns as $item) {
            $value = trim($row[$item['column']] ?? '');
            if ($value === '' && $this->commentSkipEmpty) {
                continue;
            }
            $lines[] = $item['label'] . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
