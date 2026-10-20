<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests\Engine;

use GlpiPlugin\Entitysync\Engine\Sync\Operation;
use GlpiPlugin\Entitysync\Engine\Sync\Planner;
use GlpiPlugin\Entitysync\Engine\Tree\Path;
use GlpiPlugin\Entitysync\Tests\TestCase;

final class PlannerTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function levels(): array
    {
        return [
            ['label' => 'Org', 'key_columns' => ['Org'], 'comment_columns' => [['column' => 'Info']]],
            ['label' => 'Site', 'key_columns' => ['Site']],
        ];
    }

    public function testInitialImportAddsEverythingWithDependencies(): void
    {
        $plan = $this->plan("Org;Site;Info\nACME;Paris;i\n", $this->config($this->levels()), $this->state([0 => ['Root', null]]));

        $this->assertSame(['add' => ['ACME', 'ACME > Paris'], 'link' => [], 'update' => [], 'delete' => []], $this->summary($plan));
        [$org, $site] = [$plan->ofType('add')[0], $plan->ofType('add')[1]];
        $this->assertSame([$org->id()], $site->requires);
        $this->assertSame(0, $org->parentEntityId);
        $this->assertSame(null, $site->parentEntityId);
    }

    public function testAdoptsExistingUnmanagedEntityWithSameName(): void
    {
        $state = $this->state([0 => ['Root', null], 5 => ['acme', 0, 'old']]);
        $plan = $this->plan("Org;Site;Info\nACME;Paris;i\n", $this->config($this->levels()), $state);

        $this->assertSame(['ACME'], $this->summary($plan)['link']);
        $link = $plan->ofType('link')[0];
        $this->assertSame(['name', 'comment'], array_column($link->changes, 'field'));
        // The child is added under the adopted entity and requires the link.
        $add = $plan->ofType('add')[0];
        $this->assertSame(5, $add->parentEntityId);
        $this->assertSame([$link->id()], $add->requires);
    }

    public function testUpdatesRenameCommentAndRestoresParent(): void
    {
        $state = $this->state(
            [0 => ['Root', null], 10 => ['ACME', 0, 'Info: i'], 11 => ['Old name', 0], 12 => ['Other', 0]],
            [Path::id(['ACME']) => 10, Path::id(['ACME', 'Paris']) => 11],
        );
        $levels = $this->levels();
        $levels[1]['name_column'] = 'Label';
        $plan = $this->plan("Org;Site;Info;Label\nACME;Paris;i;Paris centre\n", $this->config($levels), $state);

        $this->assertSame(['add' => [], 'link' => [], 'update' => ['ACME > Paris centre'], 'delete' => []], $this->summary($plan));
        $changes = $plan->ofType('update')[0]->changes;
        $this->assertSame(['name', 'parent'], array_column($changes, 'field'));
        $this->assertSame('Root', $changes[1]['old']);
        $this->assertSame('ACME', $changes[1]['new']);
    }

    public function testNoChangeProducesNoOperation(): void
    {
        $state = $this->state(
            [0 => ['Root', null], 10 => ['ACME', 0, "Info: i\r\n"], 11 => ['Paris', 10]],
            [Path::id(['ACME']) => 10, Path::id(['ACME', 'Paris']) => 11],
        );
        $plan = $this->plan("Org;Site;Info\nACME;Paris;i\n", $this->config($this->levels()), $state);

        $this->assertTrue($plan->isEmpty());
    }

    public function testDeletesOnlyManagedEntitiesAndBlocksWhenNeeded(): void
    {
        $state = $this->state(
            [
                0  => ['Root', null],
                10 => ['ACME', 0, 'Info: i'],
                11 => ['Paris', 10],
                12 => ['Lyon', 10],
                13 => ['Manual', 12],
                20 => ['Gone', 0, 'Info: g'],
                21 => ['Gone child', 20],
                30 => ['Unmanaged', 0],
            ],
            [
                Path::id(['ACME']) => 10,
                Path::id(['ACME', 'Paris']) => 11,
                Path::id(['ACME', 'Lyon']) => 12,
                Path::id(['Gone']) => 20,
                Path::id(['Gone', 'Gone child']) => 21,
            ],
        );
        $usage = static fn (array $ids): array => in_array(21, $ids, true) ? [21 => ['Contains 3 computer(s).']] : [];
        $plan = $this->plan("Org;Site;Info\nACME;Paris;i\n", $this->config($this->levels()), $state, $usage);

        $deletes = [];
        foreach ($plan->ofType('delete') as $op) {
            $deletes[$op->displayPath] = $op;
        }
        $this->assertSame(['ACME > Lyon', 'Gone', 'Gone > Gone child'], array_keys($deletes));
        $this->assertTrue($deletes['ACME > Lyon']->isBlocked(), 'Lyon has an unmanaged child');
        $this->assertSame(['Contains 3 computer(s).'], $deletes['Gone > Gone child']->blockReasons);
        $this->assertTrue($deletes['Gone']->isBlocked(), 'block propagates to the parent');
        $this->assertSame([$deletes['Gone > Gone child']->id()], $deletes['Gone']->requires);
    }

    public function testRowsInErrorProtectTheirEntitiesFromDeletion(): void
    {
        $levels = [['label' => 'Org', 'key_columns' => ['Org'], 'name_rules' => [['pattern' => '^DROP$', 'replacement' => '']]]];
        $state = $this->state([0 => ['Root', null], 10 => ['DROP', 0]], [Path::id(['DROP']) => 10]);
        $plan = $this->plan("Org\nDROP\n", $this->config($levels), $state);

        $this->assertTrue($plan->isEmpty());
    }

    public function testKeyChangeRelinksInsteadOfDeleteAndAdd(): void
    {
        $levels = [['label' => 'Org', 'key_columns' => ['Code'], 'name_column' => 'Label']];
        $state = $this->state([0 => ['Root', null], 10 => ['ACME', 0]], [Path::id(['OLD']) => 10]);
        $plan = $this->plan("Code;Label\nNEW;ACME\n", $this->config($levels), $state);

        $this->assertSame(['add' => [], 'link' => ['ACME'], 'update' => [], 'delete' => []], $this->summary($plan));
        $this->assertSame(Path::id(['OLD']), $plan->ofType('link')[0]->previousPathId);
    }

    public function testRenameCollidingWithUnmanagedSiblingIsBlocked(): void
    {
        $levels = [['label' => 'Org', 'key_columns' => ['Code'], 'name_column' => 'Label']];
        $state = $this->state([0 => ['Root', null], 10 => ['ACME', 0], 11 => ['Globex', 0]], [Path::id(['C1']) => 10]);
        $plan = $this->plan("Code;Label\nC1;GLOBEX\n", $this->config($levels), $state);

        $update = $plan->ofType('update')[0];
        $this->assertTrue($update->isBlocked());
    }

    public function testEntityManagedByAnotherModelIsNotAdopted(): void
    {
        $state = $this->state([0 => ['Root', null], 10 => ['ACME', 0]], [], [10]);
        $plan = $this->plan("Org;Site;Info\nACME;Paris;i\n", $this->config($this->levels()), $state);

        $this->assertTrue($plan->ofType('link')[0]->isBlocked());
        $this->assertTrue($plan->ofType('add')[0]->isBlocked(), 'children depend on the blocked link');
    }

    public function testStaleLinksAreReportedAndNodeIsAddedAgain(): void
    {
        $state = $this->state([0 => ['Root', null]], [Path::id(['ACME']) => 99]);
        $plan = $this->plan("Org;Site;Info\nACME;;i\n", $this->config($this->levels()), $state);

        $this->assertSame(['ACME'], $this->summary($plan)['add']);
        $this->assertSame([1], $plan->staleLinkIds);
    }

    public function testForbiddenTypesBlockOperations(): void
    {
        $config = $this->config($this->levels());
        $build = $this->build("Org;Site;Info\nACME;;i\n", $config);
        $plan = (new Planner(0, null, ['add' => 'No right']))->plan($build, $this->state([0 => ['Root', null]]));

        $this->assertSame(['No right'], $plan->ofType('add')[0]->blockReasons);
    }

    public function testSubRootAndDisplayPathsAreRelative(): void
    {
        $state = $this->state(
            [0 => ['Root', null], 1 => ['Customers', 0], 10 => ['ACME', 1], 11 => ['Lyon', 10]],
            [Path::id(['ACME']) => 10, Path::id(['ACME', 'Lyon']) => 11],
        );
        $plan = $this->plan("Org;Site;Info\nACME;Paris;\n", $this->config($this->levels(), null, 1), $state);

        $this->assertSame(['ACME > Lyon'], $this->summary($plan)['delete']);
        $this->assertSame(['ACME > Paris'], $this->summary($plan)['add']);
        $this->assertSame([], $this->summary($plan)['update'], 'an empty managed comment equals a null comment');
    }

    public function testExecutionOrder(): void
    {
        $state = $this->state(
            [0 => ['Root', null], 10 => ['Old', 0], 11 => ['Old child', 10]],
            [Path::id(['Old']) => 10, Path::id(['Old', 'Old child']) => 11],
        );
        $plan = $this->plan("Org;Site;Info\nNew;Site;\n", $this->config($this->levels()), $state);

        $order = array_map(
            static fn (Operation $op): string => $op->type . ':' . $op->displayPath,
            $plan->executionOrder(array_keys($plan->operations)),
        );
        $this->assertSame(['delete:Old > Old child', 'delete:Old', 'add:New', 'add:New > Site'], $order);
    }
}
