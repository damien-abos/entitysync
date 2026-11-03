<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests\Engine;

use GlpiPlugin\Entitysync\Engine\Config\ModelExport;
use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Engine\Filter\FilterEvaluator;
use GlpiPlugin\Entitysync\Tests\TestCase;

final class ModelExportTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $model = $this->model();
        $json = ModelExport::encode(ModelExport::build($model, '1.2.3', new \DateTimeImmutable('2026-10-27T10:00:00+01:00')));

        $document = json_decode($json, true);
        $this->assertSame('entitysync-model', $document['format']);
        $this->assertSame(ModelExport::VERSION, $document['version']);
        $this->assertSame('1.2.3', $document['plugin_version']);
        $this->assertSame('2026-10-27T10:00:00+01:00', $document['exported_at']);
        $this->assertTrue(str_contains($json, 'Société'), 'unicode is not escaped');

        $this->assertSame($model, ModelExport::parse($json, $this->emptyLabel(...)));
    }

    public function testDefaultsForMissingOptionalFields(): void
    {
        $parsed = ModelExport::parse(json_encode([
            'format'  => 'entitysync-model',
            'version' => 1,
            'model'   => ['levels' => [['label' => 'Org', 'key_columns' => ['Org']]]],
        ]), $this->emptyLabel(...));

        $this->assertSame('', $parsed['name']);
        $this->assertSame(null, $parsed['target']);
        $this->assertSame(['delimiter' => 'auto', 'enclosure' => '"', 'encoding' => 'auto'], $parsed['csv']);
        $this->assertSame(FilterEvaluator::emptyTree(), $parsed['filter']);
        $this->assertSame([], $parsed['known_columns']);
    }

    public function testAcceptsByteOrderMark(): void
    {
        $json = "\xEF\xBB\xBF" . ModelExport::encode(ModelExport::build($this->model(), '1.0.0', new \DateTimeImmutable()));

        $this->assertSame('ACME', ModelExport::parse($json, $this->emptyLabel(...))['name']);
    }

    public function testRejectsOtherFiles(): void
    {
        $this->assertThrows(EngineException::class, fn () => ModelExport::parse('not json', $this->emptyLabel(...)));
        $this->assertThrows(EngineException::class, fn () => ModelExport::parse('{"format":"other","version":1,"model":{}}', $this->emptyLabel(...)));
        $this->assertThrows(EngineException::class, fn () => ModelExport::parse('{"format":"entitysync-model","version":1}', $this->emptyLabel(...)));
    }

    public function testRejectsNewerFormatVersion(): void
    {
        $document = ModelExport::build($this->model(), '9.0.0', new \DateTimeImmutable());
        $document['version'] = ModelExport::VERSION + 1;

        $this->assertThrows(EngineException::class, fn () => ModelExport::parse(json_encode($document), $this->emptyLabel(...)));
    }

    public function testReportsEveryConfigurationError(): void
    {
        $document = ModelExport::build($this->model(), '1.0.0', new \DateTimeImmutable());
        $document['model']['csv'] = ['delimiter' => ';;', 'enclosure' => '', 'encoding' => 'EBCDIC'];
        $document['model']['levels'][0]['key_columns'] = [];
        $document['model']['levels'][1]['name_rules'] = [['pattern' => '(', 'replacement' => '']];
        $document['model']['filter'] = ['type' => 'condition', 'column' => '', 'operator' => 'eq', 'values' => ['x']];

        try {
            ModelExport::parse(json_encode($document), $this->emptyLabel(...));
        } catch (EngineException $e) {
            $lines = explode("\n", $e->getMessage());
            foreach (['delimiter', 'text delimiter', 'encoding', 'key column', 'regular expression'] as $needle) {
                $this->assertTrue(
                    array_filter($lines, static fn (string $l): bool => str_contains($l, $needle)) !== [],
                    "error about \"$needle\" in: " . $e->getMessage(),
                );
            }
            $this->assertTrue(count($lines) >= 6, 'the filter error is reported too: ' . $e->getMessage());
            return;
        }
        throw new \RuntimeException('Expected an EngineException');
    }

    public function testRejectsModelWithoutLevels(): void
    {
        $document = ModelExport::build($this->model(), '1.0.0', new \DateTimeImmutable());
        $document['model']['levels'] = [];

        $this->assertThrows(EngineException::class, fn () => ModelExport::parse(json_encode($document), $this->emptyLabel(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private function model(): array
    {
        return [
            'name'          => 'ACME',
            'comment'       => "Société\nHR export",
            'target'        => 'Root entity > Customers',
            'csv'           => ['delimiter' => '\t', 'enclosure' => "'", 'encoding' => 'Windows-1252'],
            'levels'        => [
                ['label' => 'Org', 'key_columns' => ['Org'], 'comment_columns' => [['column' => 'Manager', 'label' => 'Manager']]],
                ['label' => 'Site', 'key_columns' => ['Site', 'Code'], 'name_rules' => [['pattern' => '^SITE-', 'replacement' => '', 'flags' => 'i']], 'empty_label' => 'No site'],
            ],
            'filter'        => [
                'type'     => 'group',
                'logic'    => 'AND',
                'negate'   => false,
                'children' => [['type' => 'condition', 'column' => 'Status', 'operator' => 'eq', 'values' => ['Active']]],
            ],
            'known_columns' => ['Org', 'Site', 'Code', 'Manager', 'Status'],
        ];
    }

    private function emptyLabel(string $label): string
    {
        return 'No ' . mb_strtolower($label);
    }
}
