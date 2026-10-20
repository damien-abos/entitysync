<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests\Engine;

use GlpiPlugin\Entitysync\Engine\Tree\Issue;
use GlpiPlugin\Entitysync\Engine\Tree\Path;
use GlpiPlugin\Entitysync\Tests\TestCase;

final class TreeBuilderTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function orgSiteProject(): array
    {
        return [
            ['label' => 'Organisation', 'key_columns' => ['Organisation']],
            ['label' => 'Site', 'key_columns' => ['Site']],
            ['label' => 'Projet', 'key_columns' => ['Projet']],
        ];
    }

    public function testIntermediateNodesAreSharedAndCreatedOnce(): void
    {
        $csv = "Organisation;Site;Projet\nACME;Paris;P1\nACME;Paris;P2\nACME;Lyon;P3\n";
        $result = $this->build($csv, $this->config($this->orgSiteProject()));

        $paths = array_map(static fn ($n) => $n->displayPath(), array_values($result->nodes));
        $this->assertSame([
            'ACME',
            'ACME > Paris',
            'ACME > Paris > P1',
            'ACME > Paris > P2',
            'ACME > Lyon',
            'ACME > Lyon > P3',
        ], $paths);
        $this->assertSame([], $result->issues);
    }

    public function testEmptyMiddleValueCreatesPlaceholderAndTrailingEmptyShortensPath(): void
    {
        $csv = "Organisation;Site;Projet\nACME;;P1\nACME;Paris;\n";
        $result = $this->build($csv, $this->config($this->orgSiteProject()));

        $paths = array_map(static fn ($n) => $n->displayPath(), array_values($result->nodes));
        $this->assertSame(['ACME', 'ACME > No site', 'ACME > No site > P1', 'ACME > Paris'], $paths);

        $placeholder = $result->nodes[Path::id(['ACME', Path::PLACEHOLDER_KEY])];
        $this->assertTrue($placeholder->isPlaceholder);
    }

    public function testPlaceholderDoesNotCollideWithRealValueOfSameKey(): void
    {
        // A real site literally named "No site" gets its own key; same name => collision error.
        $csv = "Organisation;Site;Projet\nACME;;P1\nACME;No site;P2\n";
        $result = $this->build($csv, $this->config($this->orgSiteProject()));

        $this->assertCount(1, $result->issues);
        $this->assertSame(Issue::SEVERITY_ERROR, $result->issues[0]->severity);
        $this->assertSame(3, $result->issues[0]->line);
        $this->assertTrue(isset($result->protectedPaths[Path::id(['ACME', 'No site', 'P2'])]));
    }

    public function testCustomEmptyLabel(): void
    {
        $levels = $this->orgSiteProject();
        $levels[1]['empty_label'] = 'Sans site';
        $result = $this->build("Organisation;Site;Projet\nACME;;P1\n", $this->config($levels));

        $this->assertSame('Sans site', $result->nodes[Path::id(['ACME', Path::PLACEHOLDER_KEY])]->name);
    }

    public function testCompositeKeyNameColumnRulesAndComment(): void
    {
        $levels = [
            [
                'label'           => 'Site',
                'key_columns'     => ['Country', 'City'],
                'name_rules'      => [['pattern' => '^(\w+) - (.+)$', 'replacement' => '$2 ($1)']],
                'comment_columns' => [['column' => 'Manager', 'label' => 'Responsable'], ['column' => 'Phone']],
            ],
            [
                'label'       => 'Projet',
                'key_columns' => ['Code'],
                'name_column' => 'Label',
                'name_rules'  => [['pattern' => '^prj[-_]', 'replacement' => '', 'flags' => 'i']],
            ],
        ];
        $csv = "Country;City;Manager;Phone;Code;Label\nFR;Paris;Alice;;P1;PRJ-Alpha\n";
        $result = $this->build($csv, $this->config($levels));

        $site = $result->nodes[Path::id(["FR\u{1F}Paris"])];
        $this->assertSame('Paris (FR)', $site->name);
        $this->assertSame('Responsable: Alice', $site->comment);

        $project = $result->nodes[Path::id(["FR\u{1F}Paris", 'P1'])];
        $this->assertSame('Alpha', $project->name);
        $this->assertSame(null, $project->comment, 'comments not managed without comment columns');
    }

    public function testNameColumnFallsBackToKeyWhenEmpty(): void
    {
        $levels = [['label' => 'Org', 'key_columns' => ['Code'], 'name_column' => 'Label']];
        $result = $this->build("Code;Label\nC1;\n", $this->config($levels));

        $this->assertSame('C1', $result->nodes[Path::id(['C1'])]->name);
    }

    public function testFirstRowWinsOnConflictsWithAggregatedWarning(): void
    {
        $levels = [['label' => 'Org', 'key_columns' => ['Code'], 'name_column' => 'Label', 'comment_columns' => [['column' => 'Info']]]];
        $csv = "Code;Label;Info\nC1;First;x\nC1;Second;x\nC1;First;y\n";
        $result = $this->build($csv, $this->config($levels));

        $this->assertSame('First', $result->nodes[Path::id(['C1'])]->name);
        $this->assertCount(1, $result->issues);
        $this->assertSame(Issue::SEVERITY_WARNING, $result->issues[0]->severity);
        $this->assertTrue(str_contains($result->issues[0]->message, 'lines 3, 4'), $result->issues[0]->message);
    }

    public function testNameEmptyAfterRulesIsAnErrorAndProtectsThePath(): void
    {
        $levels = [
            ['label' => 'Org', 'key_columns' => ['Org'], 'name_rules' => [['pattern' => '.*', 'replacement' => '']]],
            ['label' => 'Site', 'key_columns' => ['Site']],
        ];
        $result = $this->build("Org;Site\nACME;Paris\n", $this->config($levels));

        $this->assertSame([], $result->nodes);
        $this->assertSame(1, $result->invalidRows);
        $this->assertTrue(isset($result->protectedPaths[Path::id(['ACME'])]));
        $this->assertTrue(isset($result->protectedPaths[Path::id(['ACME', 'Paris'])]));
    }

    public function testSiblingNameCollisionIsCaseAndAccentInsensitive(): void
    {
        $levels = [['label' => 'Org', 'key_columns' => ['Code'], 'name_column' => 'Label']];
        $csv = "Code;Label\nC1;Société\nC2;SOCIETE\n";
        $result = $this->build($csv, $this->config($levels));

        $this->assertCount(1, $result->nodes);
        $this->assertSame(Issue::SEVERITY_ERROR, $result->issues[0]->severity);
    }

    public function testFilterAndBlankKeys(): void
    {
        $filter = ['type' => 'group', 'logic' => 'AND', 'children' => [
            ['type' => 'condition', 'column' => 'Status', 'operator' => 'eq', 'values' => ['Active']],
        ]];
        $levels = [['label' => 'Org', 'key_columns' => ['Org']]];
        $result = $this->build("Org;Status\nA;Active\nB;Closed\n;Active\n", $this->config($levels, $filter));

        $this->assertSame(3, $result->totalRows);
        $this->assertSame(1, $result->filteredOutRows);
        $this->assertSame(1, $result->invalidRows);
        $this->assertCount(1, $result->nodes);
    }

    public function testConfigValidation(): void
    {
        $config = $this->config([
            ['label' => 'Org', 'key_columns' => []],
            ['label' => 'Site', 'key_columns' => ['Nope'], 'name_rules' => [['pattern' => '(', 'replacement' => '']]],
        ]);
        $errors = $config->validate(['Org']);

        $this->assertCount(3, $errors, implode(' | ', $errors));
    }
}
