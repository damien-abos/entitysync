<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests\Engine;

use GlpiPlugin\Entitysync\Engine\Sync\Applier;
use GlpiPlugin\Entitysync\Engine\Sync\ExistingState;
use GlpiPlugin\Entitysync\Engine\Sync\GatewayInterface;
use GlpiPlugin\Entitysync\Engine\Tree\Path;
use GlpiPlugin\Entitysync\Tests\TestCase;

/**
 * In-memory GLPI stand-in.
 */
final class MemoryGateway implements GatewayInterface
{
    /** @var array<int, array{id: int, name: string, comment: string|null, parent_id: int|null, completename: string}> */
    public array $entities = [];

    /** @var array<string, array{id: int, entity_id: int}> */
    public array $links = [];

    /** @var list<string> */
    public array $failOn = [];

    private int $nextId = 100;
    private int $nextLink = 1;

    public function createEntity(string $name, ?string $comment, int $parentId): int
    {
        if (in_array($name, $this->failOn, true)) {
            throw new \RuntimeException('Simulated failure');
        }
        $id = $this->nextId++;
        $this->entities[$id] = ['id' => $id, 'name' => $name, 'comment' => $comment, 'parent_id' => $parentId, 'completename' => ''];

        return $id;
    }

    public function updateEntity(int $entityId, array $fields): void
    {
        if (isset($fields['name'])) {
            $this->entities[$entityId]['name'] = $fields['name'];
        }
        if (isset($fields['comment'])) {
            $this->entities[$entityId]['comment'] = $fields['comment'];
        }
        if (isset($fields['entities_id'])) {
            $this->entities[$entityId]['parent_id'] = $fields['entities_id'];
        }
    }

    public function deleteEntity(int $entityId): void
    {
        unset($this->entities[$entityId]);
    }

    public function saveLink(string $pathId, int $entityId): void
    {
        $this->links[$pathId] = ['id' => $this->links[$pathId]['id'] ?? $this->nextLink++, 'entity_id' => $entityId];
    }

    public function removeLink(string $pathId): void
    {
        unset($this->links[$pathId]);
    }

    public function removeLinksById(array $linkIds): void
    {
        $this->links = array_filter($this->links, static fn (array $l): bool => !in_array($l['id'], $linkIds, true));
    }

    public function state(): ExistingState
    {
        $entities = $this->entities;
        foreach ($entities as $id => $entity) {
            $names = [];
            $current = $id;
            while ($current !== null && isset($entities[$current])) {
                array_unshift($names, $entities[$current]['name']);
                $current = $entities[$current]['parent_id'];
            }
            $entities[$id]['completename'] = implode(' > ', $names);
        }

        return new ExistingState($entities, $this->links);
    }
}

final class ApplierTest extends TestCase
{
    private const CSV = "Organisation;Site;Projet\nACME;Paris;P1\nACME;;P2\nGlobex;Lyon;\n";

    /**
     * @return list<array<string, mixed>>
     */
    private function levels(): array
    {
        return [
            ['label' => 'Organisation', 'key_columns' => ['Organisation']],
            ['label' => 'Site', 'key_columns' => ['Site']],
            ['label' => 'Projet', 'key_columns' => ['Projet']],
        ];
    }

    private function gateway(): MemoryGateway
    {
        $gateway = new MemoryGateway();
        $gateway->entities[0] = ['id' => 0, 'name' => 'Root', 'comment' => null, 'parent_id' => null, 'completename' => ''];

        return $gateway;
    }

    public function testFullApplyThenReimportIsIdempotent(): void
    {
        $gateway = $this->gateway();
        $config = $this->config($this->levels());

        $plan = $this->plan(self::CSV, $config, $gateway->state());
        $report = (new Applier($gateway))->apply($plan, array_keys($plan->operations));

        $this->assertSame(7, $report['counts']['add']);
        $this->assertSame(0, $report['counts']['failed']);
        $this->assertCount(7, $gateway->links);

        $names = array_column($gateway->entities, 'name');
        sort($names);
        $this->assertSame(['ACME', 'Globex', 'Lyon', 'No site', 'P1', 'P2', 'Paris', 'Root'], $names);

        $again = $this->plan(self::CSV, $config, $gateway->state());
        $this->assertTrue($again->isEmpty(), 'second run proposes nothing');
    }

    public function testSelectingAChildAutoSelectsItsParents(): void
    {
        $gateway = $this->gateway();
        $plan = $this->plan(self::CSV, $this->config($this->levels()), $gateway->state());

        $p1 = null;
        foreach ($plan->ofType('add') as $op) {
            if ($op->displayPath === 'ACME > Paris > P1') {
                $p1 = $op;
            }
        }
        $report = (new Applier($gateway))->apply($plan, [$p1->id()]);

        $this->assertSame(3, $report['counts']['add']);
        $this->assertCount(2, $report['auto_selected']);
    }

    public function testFailureSkipsDependents(): void
    {
        $gateway = $this->gateway();
        $gateway->failOn = ['ACME'];
        $plan = $this->plan(self::CSV, $this->config($this->levels()), $gateway->state());
        $report = (new Applier($gateway))->apply($plan, array_keys($plan->operations));

        $this->assertSame(1, $report['counts']['failed']);
        $this->assertSame(4, $report['counts']['skipped'], 'Paris, P1, No site, P2');
        $this->assertSame(2, $report['counts']['add'], 'Globex and Lyon');
    }

    public function testDeleteRenameAndRelinkRoundTrip(): void
    {
        $gateway = $this->gateway();
        $config = $this->config($this->levels());
        $plan = $this->plan(self::CSV, $config, $gateway->state());
        (new Applier($gateway))->apply($plan, array_keys($plan->operations));

        // Globex disappears, P1 moves under a filled site.
        $csv = "Organisation;Site;Projet\nACME;Paris;P1\nACME;Nord;P2\n";
        $plan = $this->plan($csv, $config, $gateway->state());
        $summary = $this->summary($plan);
        $this->assertSame(['ACME > Nord', 'ACME > Nord > P2'], $summary['add']);
        $this->assertSame(['ACME > No site', 'ACME > No site > P2', 'Globex', 'Globex > Lyon'], $summary['delete']);

        $report = (new Applier($gateway))->apply($plan, array_keys($plan->operations));
        $this->assertSame(0, $report['counts']['failed']);
        $this->assertSame(0, $report['counts']['skipped']);
        $this->assertTrue($this->plan($csv, $config, $gateway->state())->isEmpty());
    }

    public function testStaleLinksAreRemoved(): void
    {
        $gateway = $this->gateway();
        $gateway->links[Path::id(['Ghost'])] = ['id' => 42, 'entity_id' => 999];
        $plan = $this->plan("Organisation;Site;Projet\nACME;;\n", $this->config($this->levels()), $gateway->state());
        (new Applier($gateway))->apply($plan, []);

        $this->assertFalse(isset($gateway->links[Path::id(['Ghost'])]));
    }

    /**
     * Simulates a background job: each "run" recomputes the plan from the current
     * state and applies batches of the remaining selected operations.
     *
     * @return array{statuses: array<string, string>, runs: int}
     */
    private function simulateJob(MemoryGateway $gateway, string $csv, int $batchSize, int $batchesPerRun): array
    {
        $config = $this->config($this->levels());
        $selected = array_keys($this->plan($csv, $config, $gateway->state())->operations);
        $statuses = [];
        $runs = 0;
        while (true) {
            $plan = $this->plan($csv, $config, $gateway->state());
            $remaining = array_values(array_filter(
                $selected,
                static fn (string $id): bool => $plan->get($id) !== null && !isset($statuses[$id]),
            ));
            if ($remaining === []) {
                break;
            }
            $runs++;
            $this->assertTrue($runs < 50, 'job terminates');
            $ordered = array_map(static fn ($op) => $op->id(), $plan->executionOrder($remaining));
            $applier = new Applier($gateway);
            foreach (array_slice(array_chunk($ordered, $batchSize), 0, $batchesPerRun) as $batch) {
                foreach ($applier->applyBatch($plan, $batch)['results'] as $result) {
                    $statuses[$result['id']] = $result['status'];
                }
            }
        }

        return ['statuses' => $statuses, 'runs' => $runs];
    }

    public function testBatchedRunsGiveTheSameResultAsASingleApplication(): void
    {
        $gateway = $this->gateway();
        $job = $this->simulateJob($gateway, self::CSV, 2, 1);

        $this->assertSame(4, $job['runs'], '7 operations by batches of 2');
        $this->assertSame(array_fill(0, 7, 'done'), array_values($job['statuses']));
        $this->assertTrue($this->plan(self::CSV, $this->config($this->levels()), $gateway->state())->isEmpty());
    }

    public function testSeveralBatchesInOneRunShareCreatedParents(): void
    {
        $gateway = $this->gateway();
        $job = $this->simulateJob($gateway, self::CSV, 1, 10);

        $this->assertSame(1, $job['runs']);
        $this->assertSame(7, count(array_filter($job['statuses'], static fn (string $s): bool => $s === 'done')));
    }

    public function testFailedParentInAnEarlierRunSkipsItsChildrenLater(): void
    {
        $gateway = $this->gateway();
        $gateway->failOn = ['ACME'];
        $job = $this->simulateJob($gateway, self::CSV, 1, 1);

        $counts = array_count_values($job['statuses']);
        $this->assertSame(['failed' => 1, 'done' => 2, 'skipped' => 4], [
            'failed'  => $counts['failed'] ?? 0,
            'done'    => $counts['done'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
        ]);
    }
}
