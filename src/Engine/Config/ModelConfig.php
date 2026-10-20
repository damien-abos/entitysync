<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Config;

use GlpiPlugin\Entitysync\Engine\Filter\FilterEvaluator;
use GlpiPlugin\Entitysync\Engine\Util\Msg;

/**
 * Full import model configuration as used by the engine.
 */
final class ModelConfig
{
    /**
     * @param list<LevelConfig>         $levels
     * @param array<string, mixed>|null $filter
     */
    public function __construct(
        public readonly int $modelId,
        public readonly int $rootEntityId,
        public readonly array $levels,
        public readonly ?array $filter,
    ) {}

    /**
     * @param list<array<string, mixed>>  $levels
     * @param array<string, mixed>|null   $filter
     * @param callable(string): string    $defaultEmptyLabel Receives the level label, returns the default placeholder name.
     */
    public static function fromArrays(
        int $modelId,
        int $rootEntityId,
        array $levels,
        ?array $filter,
        callable $defaultEmptyLabel,
    ): self {
        $parsed = [];
        foreach ($levels as $index => $level) {
            if (!is_array($level)) {
                continue;
            }
            $label = trim((string) ($level['label'] ?? ''));
            if ($label === '') {
                $label = Msg::t('Level %d', $index + 1);
                $level['label'] = $label;
            }
            $parsed[] = LevelConfig::fromArray($level, $defaultEmptyLabel($label));
        }

        return new self($modelId, $rootEntityId, $parsed, $filter);
    }

    public function filterEvaluator(): FilterEvaluator
    {
        return new FilterEvaluator($this->filter);
    }

    /**
     * @param list<string>|null $availableColumns When provided, referenced columns must exist.
     *
     * @return list<string>
     */
    public function validate(?array $availableColumns = null): array
    {
        $errors = [];
        if ($this->levels === []) {
            $errors[] = Msg::t('At least one level is required.');
        }
        foreach ($this->levels as $index => $level) {
            array_push($errors, ...$level->validate($index + 1, $availableColumns));
        }
        array_push($errors, ...$this->filterEvaluator()->validate($availableColumns));

        return $errors;
    }
}
