<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests\Engine;

use GlpiPlugin\Entitysync\Engine\Sync\Operation;
use GlpiPlugin\Entitysync\Engine\Sync\Plan;
use GlpiPlugin\Entitysync\Engine\Sync\Selection;
use GlpiPlugin\Entitysync\Engine\Tree\Path;
use GlpiPlugin\Entitysync\Tests\TestCase;

final class SelectionTest extends TestCase
{
    private const CSV = "Org;Site;Info\nACME;Paris;\nACME;Lyon;\nGlobex;Nantes;\n";

    /**
     * @return array{0: Plan, 1: array<string, array<string, mixed>>, 2: array<string, string>} plan, ops, path => id
     */
    private function fixture(): array
    {
        $levels = [['label' => 'Org', 'key_columns' => ['Org']], ['label' => 'Site', 'key_columns' => ['Site']]];
        $state = $this->state(
            [0 => ['Root', null], 10 => ['Old', 0], 11 => ['Old child', 10], 12 => ['Busy', 0]],
            [Path::id(['Old']) => 10, Path::id(['Old', 'Old child']) => 11, Path::id(['Busy']) => 12],
        );
        $usage = static fn (array $ids): array => [12 => ['Contains items.']];
        $plan = $this->plan(self::CSV, $this->config($levels), $state, $usage);
        $ops = [];
        $byPath = [];
        foreach ($plan->operations as $id => $op) {
            $ops[$id] = $op->toArray();
            $byPath[$op->type . ':' . $op->displayPath] = $id;
        }

        return [$plan, $ops, $byPath];
    }

    public function testDefaultSelectionSkipsDeletionsAndBlocked(): void
    {
        [, $ops, $ids] = $this->fixture();
        $selection = new Selection($ops, Selection::defaultIds($ops));

        $this->assertSame(5, $selection->summary()['selected']);
        $this->assertSame(0, $selection->summary()['deletions']);
        $this->assertFalse($selection->isSelectable($ids['delete:Busy']));
    }

    public function testSelectingAChildSelectsItsParentAndDeselectingAParentCascades(): void
    {
        [, $ops, $ids] = $this->fixture();
        $selection = new Selection($ops, []);

        $selection->select([$ids['add:ACME > Paris']]);
        $this->assertTrue($selection->isSelected($ids['add:ACME']));
        $this->assertSame(2, $selection->summary()['selected']);

        $selection->select([$ids['add:ACME > Lyon']]);
        $selection->deselect([$ids['add:ACME']]);
        $this->assertSame([], $selection->ids());
    }

    public function testDeletionOfAParentPullsItsManagedChildren(): void
    {
        [, $ops, $ids] = $this->fixture();
        $selection = new Selection($ops, []);
        $selection->select([$ids['delete:Old']]);

        $this->assertTrue($selection->isSelected($ids['delete:Old > Old child']));
        $this->assertSame(2, $selection->summary()['deletions']);

        // Blocked operations are never selected, even explicitly.
        $selection->select([$ids['delete:Busy']]);
        $this->assertSame(2, $selection->summary()['deletions']);
    }

    public function testMatchingPagingAndSectionCounters(): void
    {
        [, $ops] = $this->fixture();
        $selection = new Selection($ops, []);

        $this->assertCount(2, $selection->matching(Operation::TYPE_ADD, 'acme >'));
        $page = $selection->page(Operation::TYPE_ADD, '', 2, 2);
        $this->assertSame(['total' => 5, 'page' => 2, 'pages' => 3, 'size' => 2], array_diff_key($page, ['ids' => 1]));
        $this->assertCount(2, $page['ids']);
        $this->assertSame(1, $selection->page(Operation::TYPE_ADD, 'nothing', 4, 2)['page'], 'page is clamped');

        // Select all the additions matching "acme" (across pages), then everything.
        $selection->selectMatching(Operation::TYPE_ADD, 'ACME');
        $summary = $selection->summary('acme');
        $this->assertSame(['total' => 5, 'matching' => 3, 'selectable' => 3, 'selected' => 3], $summary['sections']['add']);
        $this->assertSame(3, $selection->summary()['selected']);

        $selection->selectMatching(null);
        $this->assertSame(['total' => 3, 'matching' => 3, 'selectable' => 2, 'selected' => 2], $selection->summary()['sections']['delete']);
        $selection->deselectMatching(Operation::TYPE_DELETE);
        $this->assertSame(0, $selection->summary()['deletions']);
    }

    public function testUnknownAndBlockedInitialIdsAreIgnored(): void
    {
        [, $ops, $ids] = $this->fixture();
        $selection = new Selection($ops, ['0000000000000000', $ids['delete:Busy'], $ids['add:Globex']]);

        $this->assertSame([$ids['add:Globex']], $selection->ids());
    }
}
