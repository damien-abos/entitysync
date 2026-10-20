<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Tree;

use GlpiPlugin\Entitysync\Engine\Config\ModelConfig;
use GlpiPlugin\Entitysync\Engine\Csv\CsvData;
use GlpiPlugin\Entitysync\Engine\Util\Msg;
use GlpiPlugin\Entitysync\Engine\Util\Text;

/**
 * Turns filtered CSV rows into the desired entity tree.
 *
 * Rules:
 * - the key path of a row is the list of level keys; every prefix of the path is a node,
 *   so intermediate entities are created once and shared by all rows;
 * - trailing empty keys shorten the path (the row stops at the last filled level);
 * - an empty key in the middle of the path becomes a placeholder node named after the
 *   level's "empty label" (e.g. "No site"), with a reserved key that cannot collide with
 *   a real CSV value;
 * - when several rows describe the same node with different names or comments, the first
 *   row wins and a warning is raised;
 * - two different keys producing the same name under the same parent are an error
 *   (GLPI enforces unique names among siblings): the second node is excluded.
 */
final class TreeBuilder
{
    /** GLPI `glpi_entities.name` is a VARCHAR(255). */
    private const MAX_NAME_LENGTH = 255;

    /** Maximum number of line numbers listed in an aggregated warning. */
    private const MAX_LISTED_LINES = 5;

    public function __construct(private readonly ModelConfig $config) {}

    public function build(CsvData $csv): BuildResult
    {
        $filter = $this->config->filterEvaluator();
        $levels = $this->config->levels;

        /** @var array<string, Node> $nodes */
        $nodes = [];
        /** @var array<string, array<string, string>> $siblings parent path id => normalized name => path id */
        $siblings = [];
        /** @var array<string, true> $excluded */
        $excluded = [];
        /** @var array<string, true> $protected */
        $protected = [];
        /** @var array<string, array{lines: list<int>, fields: array<string, true>}> $conflicts */
        $conflicts = [];
        $issues = [];
        $filteredOut = 0;
        $invalid = 0;

        foreach ($csv->rows as $line => $row) {
            if (!$filter->matches($row)) {
                $filteredOut++;
                continue;
            }

            $keys = array_map(static fn ($level): string => $level->keyOf($row), $levels);
            $last = -1;
            foreach ($keys as $index => $key) {
                if ($key !== '') {
                    $last = $index;
                }
            }
            if ($last < 0) {
                $invalid++;
                $issues[] = Issue::warning(Msg::t('Every key column is empty: row ignored.'), $line);
                continue;
            }

            $segments = [];
            $namePath = [];
            $parentPathId = null;
            for ($index = 0; $index <= $last; $index++) {
                $level = $levels[$index];
                $isPlaceholder = $keys[$index] === '';
                $segments[] = $isPlaceholder ? Path::PLACEHOLDER_KEY : $keys[$index];
                $pathId = Path::id($segments);

                if (isset($excluded[$pathId])) {
                    // Subtree excluded by a previous error: protect the rest of the row's path.
                    $this->protectRemainder($protected, $segments, $keys, $index, $last);
                    break;
                }

                if ($isPlaceholder) {
                    $name = $level->emptyLabel;
                    $comment = $level->commentColumns === [] ? null : '';
                } else {
                    $rawName = $level->rawNameOf($row);
                    $name = $level->applyNameRules($rawName);
                    $comment = $level->commentOf($row);

                    if ($name === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
                        $invalid++;
                        $issues[] = Issue::error(
                            $name === ''
                                ? Msg::t('Level "%1$s": the name of "%2$s" is empty after the replacement rules: row ignored from this level.', $level->label, $rawName)
                                : Msg::t('Level "%1$s": the name of "%2$s" exceeds 255 characters: row ignored from this level.', $level->label, $rawName),
                            $line,
                        );
                        $this->protectRemainder($protected, $segments, $keys, $index, $last);
                        break;
                    }
                }

                if (isset($nodes[$pathId])) {
                    $existing = $nodes[$pathId];
                    $fields = [];
                    if ($existing->name !== $name) {
                        $fields['name'] = true;
                    }
                    if (Text::normalizeMultiline($existing->comment) !== Text::normalizeMultiline($comment)) {
                        $fields['comment'] = true;
                    }
                    if ($fields !== []) {
                        $conflicts[$pathId] ??= ['lines' => [], 'fields' => []];
                        $conflicts[$pathId]['lines'][] = $line;
                        $conflicts[$pathId]['fields'] += $fields;
                    }
                    $namePath[] = $existing->name;
                    $parentPathId = $pathId;
                    continue;
                }

                $siblingKey = $parentPathId ?? '';
                $normalized = Text::normalizeName($name);
                $other = $siblings[$siblingKey][$normalized] ?? null;
                if ($other !== null && $other !== $pathId) {
                    $invalid++;
                    $issues[] = Issue::error(
                        Msg::t(
                            'Level "%1$s": the name "%2$s" is already produced by another key under "%3$s" (first seen on line %4$d); GLPI requires unique names among sub-entities: row ignored from this level.',
                            $level->label,
                            $name,
                            $namePath === [] ? Msg::t('the root entity') : implode(' > ', $namePath),
                            $nodes[$other]->firstLine,
                        ),
                        $line,
                    );
                    $excluded[$pathId] = true;
                    $this->protectRemainder($protected, $segments, $keys, $index, $last);
                    break;
                }

                $namePath[] = $name;
                $nodes[$pathId] = new Node(
                    pathId: $pathId,
                    segments: $segments,
                    level: $index,
                    name: $name,
                    comment: $comment,
                    parentPathId: $parentPathId,
                    isPlaceholder: $isPlaceholder,
                    firstLine: $line,
                    namePath: $namePath,
                );
                $siblings[$siblingKey][$normalized] = $pathId;
                $parentPathId = $pathId;
            }
        }

        foreach ($conflicts as $pathId => $conflict) {
            $node = $nodes[$pathId];
            $lines = $conflict['lines'];
            $listed = implode(', ', array_slice($lines, 0, self::MAX_LISTED_LINES));
            if (count($lines) > self::MAX_LISTED_LINES) {
                $listed .= ', …';
            }
            $issues[] = Issue::warning(
                Msg::t(
                    '"%1$s": %2$s differ on %3$d other row(s) (lines %4$s); the values of line %5$d are kept.',
                    $node->displayPath(),
                    implode(' / ', array_map(
                        static fn (string $field): string => $field === 'name' ? Msg::t('name') : Msg::t('comment'),
                        array_keys($conflict['fields']),
                    )),
                    count($lines),
                    $listed,
                    $node->firstLine,
                ),
                $node->firstLine,
            );
        }

        usort($issues, static fn (Issue $a, Issue $b): int => ($a->line ?? 0) <=> ($b->line ?? 0));

        return new BuildResult(
            nodes: $nodes,
            protectedPaths: $protected,
            issues: $issues,
            totalRows: count($csv->rows),
            filteredOutRows: $filteredOut,
            invalidRows: $invalid,
        );
    }

    /**
     * Protects the path at `$from` and every deeper path of the row from deletion.
     *
     * @param array<string, true> $protected
     * @param list<string>        $segments Segments up to and including `$from`.
     * @param list<string>        $keys
     */
    private function protectRemainder(array &$protected, array $segments, array $keys, int $from, int $last): void
    {
        $protected[Path::id($segments)] = true;
        for ($index = $from + 1; $index <= $last; $index++) {
            $segments[] = $keys[$index] === '' ? Path::PLACEHOLDER_KEY : $keys[$index];
            $protected[Path::id($segments)] = true;
        }
    }
}
