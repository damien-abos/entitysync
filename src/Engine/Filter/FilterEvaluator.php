<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Filter;

use GlpiPlugin\Entitysync\Engine\Util\Msg;
use GlpiPlugin\Entitysync\Engine\Util\Text;

/**
 * Evaluates a boolean filter tree against CSV rows.
 *
 * Tree format (stored as JSON in the model):
 *
 *   group:     {"type": "group", "logic": "AND"|"OR", "negate": bool, "children": [node, ...]}
 *   condition: {"type": "condition", "column": "Status", "operator": "eq",
 *               "values": ["Active", "Pending"], "negate": bool, "case_sensitive": bool}
 *
 * A condition with several values is true when ANY value matches for positive
 * operators (eq, contains, starts_with, ends_with, regex, gt, lt, gte, lte), and
 * when NO value matches for negative operators (neq, not_contains, not_in).
 * `in` and `not_in` are list-oriented aliases of `eq` and `neq`.
 * An empty group is always true, so an empty filter keeps every row.
 */
final class FilterEvaluator
{
    /** Operators that require at least one value. */
    public const VALUE_OPERATORS = [
        'eq', 'neq', 'in', 'not_in', 'contains', 'not_contains',
        'starts_with', 'ends_with', 'regex', 'gt', 'lt', 'gte', 'lte',
    ];

    /** Operators that do not take a value. */
    public const UNARY_OPERATORS = ['empty', 'not_empty'];

    /** Negative operators and the positive operator they negate. */
    private const NEGATIONS = [
        'neq'          => 'eq',
        'not_in'       => 'eq',
        'not_contains' => 'contains',
        'not_empty'    => 'empty',
    ];

    /** @var array<string, mixed> */
    private array $tree;

    /**
     * @param array<string, mixed>|null $tree Filter tree; null or empty keeps every row.
     */
    public function __construct(?array $tree)
    {
        $this->tree = $tree === null || $tree === [] ? self::emptyTree() : $tree;
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyTree(): array
    {
        return ['type' => 'group', 'logic' => 'AND', 'negate' => false, 'children' => []];
    }

    /**
     * @param array<string, string> $row
     */
    public function matches(array $row): bool
    {
        return $this->evaluateNode($this->tree, $row);
    }

    /**
     * Validates the tree structure.
     *
     * @param list<string>|null $availableColumns When provided, columns must exist in this list.
     *
     * @return list<string> Error messages (empty when valid).
     */
    public function validate(?array $availableColumns = null): array
    {
        $errors = [];
        $this->validateNode($this->tree, $availableColumns, $errors);

        return $errors;
    }

    /**
     * @return list<string> Columns referenced by the tree.
     */
    public function referencedColumns(): array
    {
        $columns = [];
        $walk = static function (array $node) use (&$walk, &$columns): void {
            if (($node['type'] ?? '') === 'condition') {
                $columns[] = (string) ($node['column'] ?? '');
                return;
            }
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($this->tree);

        return array_values(array_unique(array_filter($columns, static fn (string $c): bool => $c !== '')));
    }

    /**
     * @param array<string, mixed>  $node
     * @param array<string, string> $row
     */
    private function evaluateNode(array $node, array $row): bool
    {
        $negate = (bool) ($node['negate'] ?? false);

        if (($node['type'] ?? 'group') === 'condition') {
            return $this->evaluateCondition($node, $row) !== $negate;
        }

        $children = array_values(array_filter($node['children'] ?? [], 'is_array'));
        if ($children === []) {
            // An empty group is neutral: it keeps every row (and its negation keeps none).
            return !$negate;
        }

        $isOr = strtoupper((string) ($node['logic'] ?? 'AND')) === 'OR';
        $result = !$isOr;
        foreach ($children as $child) {
            $value = $this->evaluateNode($child, $row);
            if ($isOr && $value) {
                $result = true;
                break;
            }
            if (!$isOr && !$value) {
                $result = false;
                break;
            }
        }

        return $result !== $negate;
    }

    /**
     * @param array<string, mixed>  $node
     * @param array<string, string> $row
     */
    private function evaluateCondition(array $node, array $row): bool
    {
        $operator = (string) ($node['operator'] ?? 'eq');
        $cell = (string) ($row[(string) ($node['column'] ?? '')] ?? '');
        $caseSensitive = (bool) ($node['case_sensitive'] ?? false);
        $values = self::normalizeValues($node);

        if (isset(self::NEGATIONS[$operator])) {
            return !$this->evaluatePositive(self::NEGATIONS[$operator], $cell, $values, $caseSensitive);
        }

        return $this->evaluatePositive($operator === 'in' ? 'eq' : $operator, $cell, $values, $caseSensitive);
    }

    /**
     * @param list<string> $values
     */
    private function evaluatePositive(string $operator, string $cell, array $values, bool $caseSensitive): bool
    {
        if ($operator === 'empty') {
            return trim($cell) === '';
        }

        foreach ($values as $value) {
            if ($this->compareOne($operator, $cell, $value, $caseSensitive)) {
                return true;
            }
        }

        return false;
    }

    private function compareOne(string $operator, string $cell, string $value, bool $caseSensitive): bool
    {
        if ($operator === 'regex') {
            $regex = Text::buildRegex($value, $caseSensitive ? '' : 'i');

            return @preg_match($regex, $cell) === 1;
        }

        if (in_array($operator, ['gt', 'lt', 'gte', 'lte'], true)) {
            $cmp = self::compareOrdered($cell, $value, $caseSensitive);

            return match ($operator) {
                'gt'  => $cmp > 0,
                'lt'  => $cmp < 0,
                'gte' => $cmp >= 0,
                'lte' => $cmp <= 0,
            };
        }

        $a = $caseSensitive ? $cell : mb_strtolower($cell, 'UTF-8');
        $b = $caseSensitive ? $value : mb_strtolower($value, 'UTF-8');

        return match ($operator) {
            'eq'          => self::isNumeric($a) && self::isNumeric($b)
                ? self::toNumber($a) == self::toNumber($b)
                : $a === $b,
            'contains'    => $b === '' || str_contains($a, $b),
            'starts_with' => str_starts_with($a, $b),
            'ends_with'   => str_ends_with($a, $b),
            default       => false,
        };
    }

    /**
     * Compares two values as numbers, then as dates, then as text.
     */
    private static function compareOrdered(string $a, string $b, bool $caseSensitive): int
    {
        if (self::isNumeric($a) && self::isNumeric($b)) {
            return self::toNumber($a) <=> self::toNumber($b);
        }

        $dateA = self::toDate($a);
        $dateB = self::toDate($b);
        if ($dateA !== null && $dateB !== null) {
            return $dateA <=> $dateB;
        }

        if (!$caseSensitive) {
            $a = mb_strtolower($a, 'UTF-8');
            $b = mb_strtolower($b, 'UTF-8');
        }

        return strcmp($a, $b);
    }

    private static function isNumeric(string $value): bool
    {
        return preg_match('/^\s*-?\d+(?:[.,]\d+)?\s*$/', $value) === 1;
    }

    private static function toNumber(string $value): float
    {
        return (float) str_replace(',', '.', trim($value));
    }

    /**
     * Parses ISO (`2026-10-20`, optionally with time) and French (`20/10/2026`) dates.
     */
    private static function toDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m) === 1) {
            return sprintf('%s%s%s%s%s%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$#', $value, $m) === 1) {
            return sprintf('%s%s%s%s%s%s', $m[3], $m[2], $m[1], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private static function normalizeValues(array $node): array
    {
        $values = $node['values'] ?? ($node['value'] ?? []);
        if (!is_array($values)) {
            $values = [$values];
        }

        return array_values(array_map(static fn ($v): string => trim((string) $v), $values));
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>|null    $availableColumns
     * @param list<string>         $errors
     */
    private function validateNode(array $node, ?array $availableColumns, array &$errors): void
    {
        $type = $node['type'] ?? 'group';

        if ($type === 'group') {
            $logic = strtoupper((string) ($node['logic'] ?? 'AND'));
            if (!in_array($logic, ['AND', 'OR'], true)) {
                $errors[] = Msg::t('Filter: unknown logic "%s".', $logic);
            }
            foreach ($node['children'] ?? [] as $child) {
                if (!is_array($child)) {
                    $errors[] = Msg::t('Filter: invalid node.');
                    continue;
                }
                $this->validateNode($child, $availableColumns, $errors);
            }
            return;
        }

        if ($type !== 'condition') {
            $errors[] = Msg::t('Filter: unknown node type "%s".', (string) $type);
            return;
        }

        $column = (string) ($node['column'] ?? '');
        $operator = (string) ($node['operator'] ?? '');
        if ($column === '') {
            $errors[] = Msg::t('Filter: a condition has no column.');
        } elseif ($availableColumns !== null && !in_array($column, $availableColumns, true)) {
            $errors[] = Msg::t('Filter: column "%s" does not exist in the file.', $column);
        }

        if (in_array($operator, self::UNARY_OPERATORS, true)) {
            return;
        }
        if (!in_array($operator, self::VALUE_OPERATORS, true)) {
            $errors[] = Msg::t('Filter: unknown operator "%s".', $operator);
            return;
        }

        $values = self::normalizeValues($node);
        if ($values === [] || ($operator !== 'eq' && $operator !== 'neq' && implode('', $values) === '')) {
            $errors[] = Msg::t('Filter: the condition on "%s" has no value.', $column);
        }
        if ($operator === 'regex') {
            foreach ($values as $value) {
                $error = Text::validateRegex($value);
                if ($error !== null) {
                    $errors[] = Msg::t('Filter: invalid regular expression "%1$s" (%2$s).', $value, $error);
                }
            }
        }
    }
}
