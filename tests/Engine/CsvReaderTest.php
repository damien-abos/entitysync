<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Tests\Engine;

use GlpiPlugin\Entitysync\Engine\Csv\CsvReader;
use GlpiPlugin\Entitysync\Engine\EngineException;
use GlpiPlugin\Entitysync\Tests\TestCase;

final class CsvReaderTest extends TestCase
{
    public function testReadsHeaderAndRowsKeyedByLine(): void
    {
        $data = (new CsvReader())->readString("Org;Site\nACME;Paris\n\nACME;Lyon\n");

        $this->assertSame(['Org', 'Site'], $data->columns);
        $this->assertSame([2 => ['Org' => 'ACME', 'Site' => 'Paris'], 4 => ['Org' => 'ACME', 'Site' => 'Lyon']], $data->rows);
        $this->assertSame(';', $data->detectedDelimiter);
    }

    public function testDetectsCommaAndHandlesQuotes(): void
    {
        $data = (new CsvReader())->readString("Org,Comment\n\"ACME, Inc\",\"He said \"\"hi\"\"\"\n");

        $this->assertSame(',', $data->detectedDelimiter);
        $this->assertSame('ACME, Inc', $data->rows[2]['Org']);
        $this->assertSame('He said "hi"', $data->rows[2]['Comment']);
    }

    public function testStripsBomAndConvertsWindows1252(): void
    {
        $bom = (new CsvReader(';'))->readString("\xEF\xBB\xBFOrg;Site\nA;B\n");
        $this->assertSame(['Org', 'Site'], $bom->columns);

        $latin = (new CsvReader(';'))->readString(mb_convert_encoding("Org;Site\nSociété;Évry\n", 'Windows-1252', 'UTF-8'));
        $this->assertSame('Windows-1252', $latin->detectedEncoding);
        $this->assertSame('Société', $latin->rows[2]['Org']);
    }

    public function testMissingCellsAreEmptyStrings(): void
    {
        $data = (new CsvReader(';'))->readString("A;B;C\n1;2\n");

        $this->assertSame(['A' => '1', 'B' => '2', 'C' => ''], $data->rows[2]);
    }

    public function testRejectsDuplicateOrEmptyHeader(): void
    {
        $this->assertThrows(EngineException::class, fn () => (new CsvReader(';'))->readString("A;A\n1;2\n"));
        $this->assertThrows(EngineException::class, fn () => (new CsvReader(';'))->readString("A;;C\n1;2;3\n"));
        $this->assertThrows(EngineException::class, fn () => (new CsvReader(';'))->readString("\n\n"));
    }

    public function testTabDelimiterAlias(): void
    {
        $data = (new CsvReader('\t'))->readString("A\tB\n1\t2\n");

        $this->assertSame(['A' => '1', 'B' => '2'], $data->rows[2]);
    }
}
