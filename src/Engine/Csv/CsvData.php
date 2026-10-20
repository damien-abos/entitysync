<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync\Engine\Csv;

/**
 * Parsed CSV content: header and rows keyed by column name.
 */
final class CsvData
{
    /**
     * @param list<string>                         $columns Header names, in file order.
     * @param array<int, array<string, string>>    $rows    Rows keyed by their line number in the file.
     * @param string                               $detectedEncoding Encoding the file was read with.
     * @param string                               $detectedDelimiter Delimiter the file was read with.
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly string $detectedEncoding,
        public readonly string $detectedDelimiter,
    ) {}

    public function hasColumn(string $column): bool
    {
        return in_array($column, $this->columns, true);
    }
}
