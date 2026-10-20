<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests;

use GlpiPlugin\Entitysync\Engine\Config\ModelConfig;
use GlpiPlugin\Entitysync\Engine\Csv\CsvReader;
use GlpiPlugin\Entitysync\Engine\Sync\ExistingState;
use GlpiPlugin\Entitysync\Engine\Sync\Plan;
use GlpiPlugin\Entitysync\Engine\Sync\Planner;
use GlpiPlugin\Entitysync\Engine\Tree\BuildResult;
use GlpiPlugin\Entitysync\Engine\Tree\TreeBuilder;

/**
 * Tiny assertion base class.
 */
abstract class TestCase
{
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                "%sExpected %s, got %s",
                $message !== '' ? $message . ': ' : '',
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertCount(int $expected, array $items, string $message = ''): void
    {
        $this->assertSame($expected, count($items), $message !== '' ? $message : 'Count mismatch');
    }

    /**
     * @param callable(): void $callback
     */
    protected function assertThrows(string $class, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new \RuntimeException(sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
        }
        throw new \RuntimeException(sprintf('Expected %s to be thrown', $class));
    }

    /**
     * @param list<array<string, mixed>> $levels
     * @param array<string, mixed>|null  $filter
     */
    protected function config(array $levels, ?array $filter = null, int $root = 0): ModelConfig
    {
        return ModelConfig::fromArrays(1, $root, $levels, $filter, static fn (string $label): string => 'No ' . mb_strtolower($label));
    }

    protected function build(string $csv, ModelConfig $config): BuildResult
    {
        return (new TreeBuilder($config))->build((new CsvReader(';'))->readString($csv));
    }

    /**
     * Builds an existing state from a compact description.
     *
     * @param array<int, array{0: string, 1: int|null, 2?: string|null}> $entities id => [name, parent id, comment]
     * @param array<string, int> $links path id => entity id
     */
    protected function state(array $entities, array $links = [], array $foreign = []): ExistingState
    {
        $records = [];
        foreach ($entities as $id => $entity) {
            $records[$id] = [
                'id'           => $id,
                'name'         => $entity[0],
                'comment'      => $entity[2] ?? null,
                'parent_id'    => $entity[1],
                'completename' => '',
            ];
        }
        // Compute complete names.
        foreach ($records as $id => $record) {
            $names = [];
            $current = $id;
            while ($current !== null && isset($records[$current])) {
                array_unshift($names, $records[$current]['name']);
                $current = $records[$current]['parent_id'];
            }
            $records[$id]['completename'] = implode(' > ', $names);
        }

        $linkRecords = [];
        $i = 1;
        foreach ($links as $pathId => $entityId) {
            $linkRecords[$pathId] = ['id' => $i++, 'entity_id' => $entityId];
        }

        return new ExistingState($records, $linkRecords, array_fill_keys($foreign, true));
    }

    protected function plan(string $csv, ModelConfig $config, ExistingState $state, ?callable $usage = null): Plan
    {
        return (new Planner($config->rootEntityId, $usage))->plan($this->build($csv, $config), $state);
    }

    /**
     * @return array<string, list<string>> type => sorted display paths
     */
    protected function summary(Plan $plan): array
    {
        $summary = [];
        foreach (['add', 'link', 'update', 'delete'] as $type) {
            $summary[$type] = array_map(static fn ($op) => $op->displayPath, $plan->ofType($type));
        }

        return $summary;
    }
}
